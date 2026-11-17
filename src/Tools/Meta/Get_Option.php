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

        if (! Option_Guard::is_plain_name($name)) {
            throw new \RuntimeException('Refusing to read an option whose name contains non-ASCII, control or invisible characters: it can match a different row than it appears to.');
        }

        if (Option_Guard::is_denylisted($name)) {
            throw new \RuntimeException(sprintf('Refusing to read sensitive option "%s".', esc_html($name)));
        }

        // Credentials come back masked, as the dedicated settings reads show
        // them: a payment gateway's settings (issue #507), every option in the
        // secret registry, and any secret-looking key inside an option array
        // (issue #865, Secret_Options).
        return ['name' => $name, 'value' => Secret_Options::mask_option($name, get_option($name))];
    }
}
