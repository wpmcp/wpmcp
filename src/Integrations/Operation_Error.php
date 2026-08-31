<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A refusal raised from inside an operation handler that must surface on the
 * dispatcher's own top-level `error` channel rather than inside a success
 * envelope.
 *
 * Handlers return plain result arrays, which the dispatcher wraps in `ok()`.
 * That is right for "the thing you asked for does not exist" (a null result is
 * a legitimate answer), but wrong for "I refuse to run this", because an agent
 * cannot tell an empty result from a refusal, and a destructive op would be
 * decorated with recoverable:false as though it had run. Throwing this from a
 * handler produces the same shape as the dispatcher's own guard refusals:
 * ['error' => ['code' => ..., 'message' => ..., 'data' => ...]].
 */
class Operation_Error extends \RuntimeException
{
    private string $error_code;

    /** @var array<string, mixed> */
    private array $error_data;

    /** @param array<string, mixed> $data */
    public function __construct(string $code, string $message, array $data = [])
    {
        parent::__construct($message);
        $this->error_code = $code;
        $this->error_data = $data;
    }

    public function error_code(): string
    {
        return $this->error_code;
    }

    /** @return array<string, mixed> */
    public function error_data(): array
    {
        return $this->error_data;
    }
}
