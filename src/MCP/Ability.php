<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

class Ability
{
    public function __construct(
        public string $name,
        public string $tier,
        public string $description,
        public array $input_schema,
        public $handler
    ) {
    }
}
