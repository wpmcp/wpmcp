<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A runtime refusal raised from inside an operation handler, converted by
 * Integration_Dispatcher into the SAME top-level error envelope every other
 * guard uses.
 *
 * Handlers must never return ['error' => ...] themselves: the dispatcher
 * nests handler output under 'result', so a returned error is
 * indistinguishable from a successful call and still carries operation_id /
 * recoverable:true. Refusals that can be decided before any write belong in
 * the op's 'validate' callable (which runs before Safe_Mutation, so no
 * snapshot row is burned); this exception is only for failures that can
 * only be discovered mid-write, e.g. mkdir() or file_put_contents() failing.
 */
class Operation_Refused extends \RuntimeException
{
    /** @var array<string,mixed> */
    private array $error_data;

    private string $error_code;

    /** @param array<string,mixed> $data */
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

    /** @return array<string,mixed> */
    public function error_data(): array
    {
        return $this->error_data;
    }
}
