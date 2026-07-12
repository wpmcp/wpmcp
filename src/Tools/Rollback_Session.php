<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Rollback_Service;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Session
{
    public function handle(array $args): array
    {
        return ['restored_count' => Rollback_Service::restore_session((string) ($args['session_id'] ?? ''))];
    }
}
