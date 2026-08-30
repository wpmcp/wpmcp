<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Token_Refresher;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report whether this site is connected to WP MCP Cloud and where. Read-only.
 *
 * token_status distinguishes a working connection from one whose refresh
 * token the cloud has rejected (issue #141): the site is still configured,
 * but its OAuth credential needs a re-connect. Phase A connections that
 * authenticate with an API key always report "ok".
 */
class Cloud_Status
{
    public function handle(array $args): array
    {
        return [
            'connected' => Cloud_Config::is_configured(),
            'url'       => Cloud_Config::base_url(),
            'token_status' => Token_Refresher::is_unhealthy() ? 'rejected' : 'ok',
        ];
    }
}
