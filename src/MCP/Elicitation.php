<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Confirm gates as elicitation (issue #387).
 *
 * Destructive tools refuse to run without confirm:true. On a client that
 * advertises form elicitation, the transport turns that refusal into a
 * question for the person instead: "allow this?", answered with a single
 * boolean. The argument stays the whole contract for every other client,
 * and an agent that passes confirm:true itself is not asked again, so no
 * existing caller changes behavior.
 *
 * How the question travels depends on the revision:
 *
 *  - 2026-07-28 is stateless. tools/call answers resultType
 *    "input_required" with one elicitation/create input request and an
 *    opaque requestState; the client asks the person and retries the same
 *    call carrying inputResponses and that state. Nothing is held on the
 *    server between the two requests, which is why this works on the
 *    stateless HTTP route as well as over stdio.
 *  - 2025-11-25 sends elicitation/create to the client mid-call. Only the
 *    stdio session has a channel for that; the adapter's HTTP transport
 *    answers with JSON only, so a 2025 HTTP client keeps the argument.
 *
 * requestState is an HMAC over the tool, the arguments (without confirm),
 * the user and an expiry. An answer is therefore consent to exactly the
 * call it was asked for: it cannot be replayed onto other arguments,
 * redeemed by another user, or forged. A missing, stale or mismatched
 * state is never read as consent; the server simply asks again.
 *
 * What counts as a confirm gate is the tool's own refusal, recognized by
 * is_confirmation_refusal(), so a gate that only applies to some calls (a
 * permanent rather than a trash delete, a write rather than a read) is
 * only asked about when it actually refuses. Tools whose unconfirmed call
 * returns a preview rather than a refusal are answered with the preview,
 * which is the point of those tools, and are never elicited.
 */
final class Elicitation
{
    /** The inputRequests / inputResponses key of the one question asked. */
    public const INPUT_KEY = 'wpmcp_confirm';

    /** Seconds an unanswered question's requestState stays redeemable. */
    public const STATE_TTL = 900;

    /** WP_Error codes and structured error codes a confirm gate refuses with. */
    private const REFUSAL_CODES = [ 'confirm_required', 'confirmation_required' ];

    /** Longest argument summary shown in the question. */
    private const MAX_ARGUMENTS_CHARS = 600;

    /**
     * Whether a client capability set supports form-mode elicitation. An
     * empty elicitation object means form mode (the spec's implicit form);
     * a client that declares only url mode does not get a form.
     *
     * @param mixed $capabilities Client capabilities (array or object).
     */
    public static function supports_form($capabilities): bool
    {
        $capabilities = self::to_array($capabilities);
        if (! is_array($capabilities) || ! array_key_exists('elicitation', $capabilities)) {
            return false;
        }

        $elicitation = self::to_array($capabilities['elicitation']);
        if (! is_array($elicitation)) {
            return false;
        }

        return array_key_exists('form', $elicitation) || ! array_key_exists('url', $elicitation);
    }

    /**
     * Whether a tool outcome is a confirm gate's refusal: the typed
     * exception, a WP_Error with a confirmation code, or the structured
     * {error:{code:"confirmation_required"}} the op dispatchers return.
     *
     * @param mixed $outcome A tool result, WP_Error or Throwable.
     */
    public static function is_confirmation_refusal($outcome): bool
    {
        if ($outcome instanceof Confirmation_Required) {
            return true;
        }
        if (is_wp_error($outcome)) {
            return in_array($outcome->get_error_code(), self::REFUSAL_CODES, true);
        }
        if (is_array($outcome) && isset($outcome['error']) && is_array($outcome['error'])) {
            return in_array($outcome['error']['code'] ?? null, self::REFUSAL_CODES, true);
        }

        return false;
    }

    /**
     * The refusal's own explanation, for the question.
     *
     * @param mixed $outcome A value is_confirmation_refusal() accepted.
     */
    public static function refusal_message($outcome): string
    {
        if ($outcome instanceof \Throwable) {
            return $outcome->getMessage();
        }
        if (is_wp_error($outcome)) {
            return $outcome->get_error_message();
        }
        if (is_array($outcome) && isset($outcome['error']['message'])) {
            return (string) $outcome['error']['message'];
        }

        return '';
    }

    /**
     * The question shown to the person: why the tool asks, reworded where
     * the refusal addresses the agent ("Pass confirm:true"), plus the tool
     * and the arguments it would run with, so the person can see what is
     * about to be changed.
     *
     * @param array<string,mixed> $args
     */
    public static function question(string $tool, array $args, string $reason): string
    {
        $reason = (string) preg_replace('/\s*Pass confirm:true to proceed\.?/i', '', $reason);
        $reason = (string) preg_replace('/\s+(and\s+)?requires confirm:true/i', ' $1needs your confirmation', $reason);
        $reason = trim(str_ireplace('confirm:true', 'confirmation', $reason));

        $summary = wp_json_encode(self::without_confirm($tool, $args));
        $summary = false === $summary ? '{}' : $summary;
        if (strlen($summary) > self::MAX_ARGUMENTS_CHARS) {
            $summary = substr($summary, 0, self::MAX_ARGUMENTS_CHARS) . '...';
        }

        $question = sprintf('Allow %s to run?', $tool);
        if ('' !== $reason) {
            $question .= ' ' . $reason;
        }

        return $question . "\nArguments: " . $summary;
    }

    /**
     * elicitation/create params: one required boolean.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function request_params(string $tool, array $args, string $reason): array
    {
        return [
            'mode'            => 'form',
            'message'         => self::question($tool, $args, $reason),
            'requestedSchema' => [
                'type'       => 'object',
                'properties' => [
                    'confirm' => [
                        'type'        => 'boolean',
                        'title'       => 'Confirm',
                        'description' => 'Allow this action.',
                        'default'     => false,
                    ],
                ],
                'required'   => [ 'confirm' ],
            ],
        ];
    }

    /**
     * The 2026-07-28 input_required result asking the question.
     *
     * @param array<string,mixed> $args The arguments as the client sent them.
     * @return array<string,mixed>
     */
    public static function input_required(string $tool, array $args, string $reason): array
    {
        return [
            'resultType'    => 'input_required',
            'inputRequests' => [
                self::INPUT_KEY => [
                    'method' => 'elicitation/create',
                    'params' => self::request_params($tool, $args, $reason),
                ],
            ],
            'requestState'  => self::sign_state($tool, $args),
        ];
    }

    /**
     * The person's answer carried on a 2026-07-28 retry: 'accept' when they
     * confirmed, 'decline' for any other answer, null when the retry carries
     * no answer bound to this exact call (no state, a forged or stale one,
     * other arguments, another user), which is never consent.
     *
     * @param array<string,mixed> $params tools/call params.
     * @param array<string,mixed> $args   The call's arguments.
     */
    public static function answer(array $params, string $tool, array $args): ?string
    {
        $responses = self::to_array($params['inputResponses'] ?? null);
        if (! is_array($responses) || ! array_key_exists(self::INPUT_KEY, $responses)) {
            return null;
        }

        $state = $params['requestState'] ?? null;
        if (! is_string($state) || ! self::verify_state($state, $tool, $args)) {
            return null;
        }

        return self::accepted($responses[ self::INPUT_KEY ]) ? 'accept' : 'decline';
    }

    /**
     * Whether an ElicitResult confirms: accepted, with confirm ticked.
     *
     * @param mixed $result
     */
    public static function accepted($result): bool
    {
        $result = self::to_array($result);
        if (! is_array($result) || 'accept' !== ($result['action'] ?? null)) {
            return false;
        }

        $content = self::to_array($result['content'] ?? null);

        return is_array($content) && true === ($content['confirm'] ?? null);
    }

    /**
     * The tool result for a question the person did not confirm.
     *
     * @return array<string,mixed>
     */
    public static function declined_result(string $tool): array
    {
        return [
            'isError' => true,
            'content' => [ [
                'type' => 'text',
                'text' => sprintf('Not confirmed: the user did not approve %s, so nothing was changed.', $tool),
            ] ],
        ];
    }

    /**
     * The arguments with confirm set, on the dispatched call when the tool
     * is the call-tool dispatcher (its gate lives on the call it forwards).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function with_confirm(string $tool, array $args, bool $confirm): array
    {
        if (self::is_dispatcher($tool)) {
            $inner            = isset($args['arguments']) && is_array($args['arguments']) ? $args['arguments'] : [];
            $inner['confirm'] = $confirm;
            $args['arguments'] = $inner;
            return $args;
        }

        $args['confirm'] = $confirm;
        return $args;
    }

    /**
     * The arguments to probe a gate with. A schema that requires confirm
     * would reject a call without it before the tool's own checks run, so
     * the probe carries confirm:false: the tool then refuses (or fails an
     * earlier check) exactly as it would for an agent that sent false.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $schema The called ability's input schema.
     * @return array<string,mixed>
     */
    public static function probe_input(string $tool, array $args, array $schema): array
    {
        $target = self::is_dispatcher($tool) && isset($args['arguments']) && is_array($args['arguments'])
            ? $args['arguments']
            : $args;

        if (self::is_dispatcher($tool)) {
            $schema = self::dispatched_schema($args);
        }

        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        if (! in_array('confirm', $required, true) || array_key_exists('confirm', $target)) {
            return $args;
        }

        return self::with_confirm($tool, $args, false);
    }

    /** @param array<string,mixed> $args */
    private static function sign_state(string $tool, array $args): string
    {
        $payload = bin2hex((string) wp_json_encode([
            't' => $tool,
            'a' => self::args_hash($tool, $args),
            'u' => get_current_user_id(),
            'e' => time() + self::STATE_TTL,
        ]));

        return $payload . '.' . hash_hmac('sha256', $payload, self::key());
    }

    /** @param array<string,mixed> $args */
    private static function verify_state(string $state, string $tool, array $args): bool
    {
        $parts = explode('.', $state);
        if (2 !== count($parts) || ! hash_equals(hash_hmac('sha256', $parts[0], self::key()), $parts[1])) {
            return false;
        }

        $decoded = ctype_xdigit($parts[0]) && 0 === strlen($parts[0]) % 2 ? hex2bin($parts[0]) : false;
        $claims  = false === $decoded ? null : json_decode($decoded, true);
        if (! is_array($claims)) {
            return false;
        }

        return $tool === ($claims['t'] ?? null)
            && self::args_hash($tool, $args) === ($claims['a'] ?? null)
            && get_current_user_id() === ($claims['u'] ?? null)
            && is_int($claims['e'] ?? null)
            && $claims['e'] >= time();
    }

    /** @param array<string,mixed> $args */
    private static function args_hash(string $tool, array $args): string
    {
        return hash('sha256', (string) wp_json_encode(self::canonical(self::without_confirm($tool, $args))));
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function without_confirm(string $tool, array $args): array
    {
        if (self::is_dispatcher($tool) && isset($args['arguments']) && is_array($args['arguments'])) {
            unset($args['arguments']['confirm']);
            return $args;
        }

        unset($args['confirm']);
        return $args;
    }

    /**
     * Key order is not meaning: sort maps recursively so a client that
     * re-serializes the same arguments differently still matches.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonical($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'canonical'], $value);
    }

    private static function key(): string
    {
        return wp_salt('auth') . '|wpmcp-elicitation';
    }

    private static function is_dispatcher(string $tool): bool
    {
        return Tool_Exposure::tool_name('wpmcp/call-tool') === $tool;
    }

    /**
     * The input schema of the ability a call-tool call dispatches to.
     *
     * @param array<string,mixed> $args call-tool arguments.
     * @return array<string,mixed>
     */
    private static function dispatched_schema(array $args): array
    {
        $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : '';
        if ('' === $name || ! function_exists('wp_has_ability') || ! wp_has_ability($name)) {
            return [];
        }

        $ability = wp_get_ability($name);

        return is_object($ability) && method_exists($ability, 'get_input_schema') ? (array) $ability->get_input_schema() : [];
    }

    /**
     * Objects to arrays, recursively, for values that may arrive decoded
     * either way (stdio decodes to arrays, callers may hand over stdClass).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function to_array($value)
    {
        if (is_object($value)) {
            $value = json_decode((string) wp_json_encode($value), true);
        }

        return $value;
    }
}
