<?php

namespace WPMCP\Tools\Analysis\SeoData;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A provider answered "slow down" (issue #304). Seo_Data_Lookup turns it into
 * a cooldown during which no request is sent at all.
 */
class Seo_Data_Rate_Limited extends \RuntimeException
{
    private int $retry_after;

    public function __construct(string $message, int $retry_after)
    {
        parent::__construct($message);
        $this->retry_after = $retry_after;
    }

    public function retry_after(): int
    {
        return $this->retry_after;
    }
}
