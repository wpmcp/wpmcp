<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A package archive Package_Archive refused, carrying a short
 * machine-readable reason alongside the human message so the caller can
 * audit why without parsing the message.
 */
class Package_Rejected extends \RuntimeException
{
    public string $reason;

    public function __construct(string $message, string $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
