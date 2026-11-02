<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Php_Snippet_Validator;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integration for the most installed snippet-manager plugin
 * (wpmcp/code-snippets-read and wpmcp/code-snippets-write pair).
 *
 * The host plugin keeps its snippets in its own {prefix}snippets table and
 * exposes them through namespaced functions (Code_Snippets\get_snippets,
 * save_snippet, activate_snippet, deactivate_snippet) and the
 * Code_Snippets\Snippet value object. Every call here goes through those
 * functions behind function_exists / class_exists guards, so a missing or
 * older host plugin yields a structured error and never a fatal.
 *
 * Safety model, mirroring our own PHP snippet tools (issues #453 and #558):
 *
 *  - a snippet is created inactive; nothing executes on create
 *  - code is run through Php_Snippet_Validator on create and update; invalid
 *    PHP is refused before anything is written, and an active snippet's code
 *    is never replaced with code that fails the lint
 *  - activation is gated by Php_Snippet_Guard::assert_execution_allowed()
 *    (the same WPMCP_ALLOW_PHP_EXEC / environment gate as run-php-snippet)
 *    and re-validates the STORED code at call time; a lint failure refuses
 *    the activation with no side effects
 *  - every write snapshots the row as a db_rows before-image so it is
 *    restorable through rollback-operation
 */
class Code_Snippets_Integration extends Integration_Dispatcher
{
    private const SCOPES = [ 'global', 'admin', 'front-end', 'single-use', 'content', 'head-content', 'footer-content', 'condition' ];

    public function integration(): string
    {
        return 'code-snippets';
    }

    public function is_available(): bool
    {
        return function_exists('Code_Snippets\get_snippets')
            && function_exists('Code_Snippets\get_snippet')
            && function_exists('Code_Snippets\save_snippet')
            && function_exists('Code_Snippets\activate_snippet')
            && function_exists('Code_Snippets\deactivate_snippet')
            && class_exists('Code_Snippets\Snippet');
    }

    /**
     * Registers only while the host plugin is loaded (like the forms adapters),
     * so the pair costs nothing in tools/list on sites without it.
     */
    public function registers_only_when_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'Snippet manager plugin (PHP snippets; activation lint-gated)';
    }

    private static function table(): string
    {
        global $wpdb;
        if (function_exists('Code_Snippets\code_snippets')) {
            $cs = \Code_Snippets\code_snippets();
            if (isset($cs->db->table) && is_string($cs->db->table) && '' !== $cs->db->table) {
                return $cs->db->table;
            }
        }
        return $wpdb->prefix . 'snippets';
    }

    /** Shape a host Snippet object into a summary row (no code). */
    private static function summarize(object $s): array
    {
        $tags = $s->tags ?? [];
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }
        return [
            'id'       => (int) ($s->id ?? 0),
            'name'     => (string) ($s->name ?? ''),
            'scope'    => (string) ($s->scope ?? 'global'),
            'status'   => ! empty($s->active) ? 'active' : 'inactive',
            'tags'     => array_values(array_map('strval', (array) $tags)),
            'priority' => (int) ($s->priority ?? 10),
        ];
    }

    private static function find(int $id): ?object
    {
        $s = \Code_Snippets\get_snippet($id);
        if (! is_object($s) || (int) ($s->id ?? 0) < 1) {
            return null;
        }
        return $s;
    }

    private static function missing(int $id): array
    {
        return [ 'code' => 'snippet_missing', 'message' => sprintf('No snippet with id %d.', $id), 'data' => [ 'id' => $id ] ];
    }

    /** Refusal payload when code fails the static validation gate, else null. */
    private static function lint_refusal(string $code, string $what): ?array
    {
        $v = Php_Snippet_Validator::validate($code);
        if ($v['syntax_valid'] && $v['safe']) {
            return null;
        }
        return [
            'code'    => 'validation_failed',
            'message' => sprintf('Refusing to %s: the snippet code does not pass static validation (advisory speed-bump, not a security boundary).', $what),
            'data'    => [ 'validation' => $v ],
        ];
    }

    private static function exec_gate_refusal(): ?array
    {
        try {
            Php_Snippet_Guard::assert_execution_allowed();
        } catch (\RuntimeException $e) {
            return [ 'code' => 'php_execution_disabled', 'message' => $e->getMessage(), 'data' => [] ];
        }
        return null;
    }

    /** db_rows before-image of one snippet row, or null when there is none to snapshot. */
    private static function row_snapshot(int $id, array $set): ?array
    {
        $table = Database_Guard::valid_table(self::table());
        if (is_wp_error($table) || $id < 1) {
            return null;
        }
        $where  = [ 'id' => $id ];
        $before = Database_Guard::before_image($table, $where, 1);
        if ([] === $before) {
            return null;
        }
        return [
            'object_type'         => 'db_rows',
            'object_id'           => $table,
            'extra_snapshot_data' => [
                'table'       => $table,
                'operation'   => 'update',
                'primary_key' => [ 'id' ],
                'where'       => $where,
                'set'         => $set,
                'rows'        => $before,
            ],
        ];
    }

    private static function id_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'id' ],
        ];
    }

    protected function operations(): array
    {
        return [
            'list-snippets' => [
                'mode'         => 'read',
                'description'  => 'List snippets (id, name, scope, status, tags, priority; no code). Filters: scope, status, tag',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'scope'  => [ 'type' => 'string', 'enum' => self::SCOPES ],
                        'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ] ],
                        'tag'    => [ 'type' => 'string' ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $out = [];
                    foreach (\Code_Snippets\get_snippets() as $s) {
                        $row = self::summarize($s);
                        if (isset($args['scope']) && $row['scope'] !== $args['scope']) {
                            continue;
                        }
                        if (isset($args['status']) && $row['status'] !== $args['status']) {
                            continue;
                        }
                        if (isset($args['tag']) && ! in_array((string) $args['tag'], $row['tags'], true)) {
                            continue;
                        }
                        $out[] = $row;
                    }
                    return [ 'snippets' => $out, 'total' => count($out) ];
                },
            ],
            'get-snippet' => [
                'mode'         => 'read',
                'description'  => 'Read one snippet with code and a static validation report',
                'input_schema' => self::id_schema(),
                'validate'     => fn (array $args) => null === self::find((int) $args['id']) ? self::missing((int) $args['id']) : null,
                'handler'      => function (array $args): array {
                    $s    = self::find((int) $args['id']);
                    $code = (string) ($s->code ?? '');
                    return [
                        'snippet'    => self::summarize($s) + [ 'description' => (string) ($s->desc ?? ''), 'code' => $code ],
                        'validation' => Php_Snippet_Validator::validate($code),
                    ];
                },
            ],
            'create-snippet' => [
                'mode'         => 'write',
                'description'  => 'Create a PHP snippet, always inactive; code must pass static validation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'minLength' => 1 ],
                        'code'        => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'scope'       => [ 'type' => 'string', 'enum' => self::SCOPES ],
                        'tags'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'priority'    => [ 'type' => 'integer' ],
                    ],
                    'required'   => [ 'name', 'code' ],
                ],
                'validate'     => fn (array $args) => self::lint_refusal((string) $args['code'], 'create snippet'),
                'handler'      => function (array $args): array {
                    $s           = new \Code_Snippets\Snippet();
                    $s->name     = (string) $args['name'];
                    $s->code     = (string) $args['code'];
                    $s->desc     = (string) ($args['description'] ?? '');
                    $s->scope    = (string) ($args['scope'] ?? 'global');
                    $s->tags     = array_values(array_map('strval', (array) ($args['tags'] ?? [])));
                    $s->priority = (int) ($args['priority'] ?? 10);
                    $s->active   = false;
                    $id          = (int) \Code_Snippets\save_snippet($s);
                    if ($id < 1) {
                        throw new Operation_Refused('save_failed', 'The snippet manager refused to save the snippet.');
                    }
                    return [ 'snippet' => self::summarize(self::find($id) ?? $s) + [ 'id' => $id ], 'validation' => Php_Snippet_Validator::validate($s->code) ];
                },
            ],
            'update-snippet-code' => [
                'mode'         => 'write',
                'description'  => 'Replace a snippet\'s code; refused when it fails static validation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'code' => [ 'type' => 'string', 'minLength' => 1 ],
                    ],
                    'required'   => [ 'id', 'code' ],
                ],
                'validate'     => function (array $args): ?array {
                    $id = (int) $args['id'];
                    if (null === self::find($id)) {
                        return self::missing($id);
                    }
                    return self::lint_refusal((string) $args['code'], 'update snippet code');
                },
                'snapshot'     => fn (array $args) => self::row_snapshot((int) $args['id'], [ 'code' => (string) $args['code'] ]),
                'handler'      => function (array $args): array {
                    $s = self::find((int) $args['id']);
                    if (null === $s) {
                        throw new Operation_Refused('snippet_missing', self::missing((int) $args['id'])['message']);
                    }
                    $s->code = (string) $args['code'];
                    \Code_Snippets\save_snippet($s);
                    return [ 'snippet' => self::summarize(self::find((int) $args['id']) ?? $s), 'validation' => Php_Snippet_Validator::validate($s->code) ];
                },
            ],
            'activate-snippet' => [
                'mode'         => 'write',
                'description'  => 'Activate a snippet; gated by WPMCP_ALLOW_PHP_EXEC and refused when its code fails static validation',
                'input_schema' => self::id_schema(),
                'validate'     => function (array $args): ?array {
                    $gate = self::exec_gate_refusal();
                    if (null !== $gate) {
                        return $gate;
                    }
                    $id = (int) $args['id'];
                    $s  = self::find($id);
                    if (null === $s) {
                        return self::missing($id);
                    }
                    return self::lint_refusal((string) ($s->code ?? ''), 'activate snippet');
                },
                'snapshot'     => fn (array $args) => self::row_snapshot((int) $args['id'], [ 'active' => 1 ]),
                'handler'      => function (array $args): array {
                    $id = (int) $args['id'];
                    // Re-checked inside the write: the code may have changed
                    // between validate() and here.
                    $s = self::find($id);
                    if (null === $s) {
                        throw new Operation_Refused('snippet_missing', self::missing($id)['message']);
                    }
                    $refusal = self::lint_refusal((string) ($s->code ?? ''), 'activate snippet');
                    if (null !== $refusal) {
                        throw new Operation_Refused('validation_failed', $refusal['message']);
                    }
                    $result = \Code_Snippets\activate_snippet($id);
                    if (is_string($result)) {
                        throw new Operation_Refused('activation_failed', $result);
                    }
                    return [ 'snippet' => self::summarize(self::find($id) ?? $s) ];
                },
            ],
            'deactivate-snippet' => [
                'mode'         => 'write',
                'description'  => 'Deactivate a snippet',
                'input_schema' => self::id_schema(),
                'validate'     => fn (array $args) => null === self::find((int) $args['id']) ? self::missing((int) $args['id']) : null,
                'snapshot'     => fn (array $args) => self::row_snapshot((int) $args['id'], [ 'active' => 0 ]),
                'handler'      => function (array $args): array {
                    $id = (int) $args['id'];
                    \Code_Snippets\deactivate_snippet($id);
                    $s = self::find($id);
                    return [ 'snippet' => null === $s ? null : self::summarize($s) ];
                },
            ],
        ];
    }
}
