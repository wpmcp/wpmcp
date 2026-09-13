<?php

namespace WPMCP\Tools\Meta;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a single wp_options value by name. Refuses a
 * conservative denylist of sensitive/core option names via Option_Guard, so
 * this generic read tool cannot be used to exfiltrate secrets (auth keys,
 * API tokens, etc.). Reads have nothing to roll back, so this never touches
 * Safe_Mutation.
 */
class Get_Option
{
    public function handle(array $args): array
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('An option name is required.');
        }

        if (Option_Guard::is_denylisted($name)) {
            throw new \RuntimeException(sprintf('Refusing to read sensitive option "%s".', esc_html($name)));
        }

        return ['name' => $name, 'value' => get_option($name)];
    }
}
