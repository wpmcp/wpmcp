<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A refusal from a PHP snippet surface that carries a machine-readable
 * reason for the governance trail (see Php_Snippet_Guard::refusal_class()).
 * A RuntimeException, so every existing caller and test that expects one
 * still sees one.
 */
class Php_Snippet_Refusal extends \RuntimeException
{
    private string $reason;

    public function __construct(string $message, string $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
