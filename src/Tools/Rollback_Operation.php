<?php

namespace WPMCP\Tools;

use WPMCP\Safety\Rollback_Service;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Operation
{
    public function handle(array $args): array
    {
        return ['restored' => Rollback_Service::restore_operation((string) ($args['operation_id'] ?? ''))];
    }
}
