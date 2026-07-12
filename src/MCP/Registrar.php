<?php

namespace WPMCP\MCP;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

class Registrar
{
    /** @var Ability[] */
    private array $abilities = [];

    public function register(Ability $a): void
    {
        if ('pro' === $a->tier && ! Gate::is_pro()) {
            return;
        }
        $this->abilities[ $a->name ] = $a;
        if (function_exists('wp_register_ability') && doing_action('wp_abilities_api_init')) {
            wp_register_ability($a->name, [
                'label'               => $a->description,
                'input_schema'        => $a->input_schema,
                'execute_callback'    => $a->handler,
                'permission_callback' => fn() => current_user_can('edit_posts'),
            ]);
        }
    }

    /** @return Ability[] */
    public function all(): array
    {
        return array_values($this->abilities);
    }
}
