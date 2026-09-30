<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

class Ability
{
    public bool $read_only_hint;
    public bool $destructive_hint;
    public bool $idempotent_hint;

    /**
     * $capability is the WordPress capability a caller must hold for this
     * ability's permission_callback to allow execution. It defaults to
     * 'edit_posts' so every existing ability keeps its original gate; only
     * sensitive tools (e.g. user management) pass a stronger capability.
     *
     * $read_only_hint, $destructive_hint, $idempotent_hint are the three MCP
     * tool annotation booleans. When left null they are derived from
     * $operation using the mapping documented in the governance spec:
     *  - read:   read_only=true,  destructive=false, idempotent=true
     *  - create: read_only=false, destructive=false, idempotent=false
     *  - update: read_only=false, destructive=false, idempotent=true
     *  - delete: read_only=false, destructive=true,  idempotent=false
     * Callers that need to deviate from this default (e.g. an update that is
     * actually an irreversible file overwrite) pass explicit booleans.
     *
     * $read_keys names input keys whose post the ability only reads although
     * its operation writes (a copy source). The permission decision checks
     * read_post for them instead of edit_post (Content_Guard::input_denial()).
     * It is not part of the tool's advertised contract.
     *
     * $objects, when set, lists the WordPress objects an invocation names
     * somewhere Content_Guard's post-id keys do not reach (an integration
     * pack's `args`, issue #450): given the input, it returns
     * Object_Guard::denial() entries. The permission decision checks each
     * one's per-object capability. Not advertised either.
     */
    public function __construct(
        public string $name,
        public string $tier,
        public string $description,
        public array $input_schema,
        public $handler,
        public string $capability = 'edit_posts',
        public string $domain = 'content',
        public string $operation = 'read',
        ?bool $read_only_hint = null,
        ?bool $destructive_hint = null,
        ?bool $idempotent_hint = null,
        public array $read_keys = [],
        public ?\Closure $objects = null
    ) {
        $this->read_only_hint   = $read_only_hint ?? ('read' === $operation);
        $this->destructive_hint = $destructive_hint ?? ('delete' === $operation);
        $this->idempotent_hint  = $idempotent_hint ?? in_array($operation, ['read', 'update'], true);
    }
}
