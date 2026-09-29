<?php

namespace WPMCP\Tools\Identity;

use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create (or overwrite, by name) a scoped identity. 'name' is required and
 * must be non-empty; 'domains', 'operations', 'abilities' (each string[])
 * and 'mode' ('allow'|'deny') are all optional and default per
 * Identity_Store::create(). 'allowed_ips' (issue #416) is validated
 * strictly here: one bad entry refuses the whole call rather than storing a
 * narrower list than the caller asked for. Overwriting without it clears it.
 * Plain option write, no Safe_Mutation/rollback.
 */
class Create_Identity
{
    public function handle(array $args): array
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('An identity name is required.');
        }

        if (array_key_exists('allowed_ips', $args)) {
            $args['allowed_ips'] = Ip_Allowlist::validate($args['allowed_ips']);
        }

        return Identity_Store::create($name, $args);
    }
}
