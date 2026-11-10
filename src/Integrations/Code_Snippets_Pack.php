<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Code_Snippet_Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Php_Snippet_Validator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Code Snippets (issue #576) on the plugin-data pair, so it adds no
 * top-level tools.
 *
 * Code Snippets 4.1 keeps a site's snippets in its own table and runs every
 * active PHP snippet on each request. Every call here goes through its own
 * API (get_snippet, get_snippets, save_snippet, deactivate_snippet and the
 * Code_Snippets\Model\Snippet class), so its caches, hooks and flat files
 * follow; only the site's snippets are touched, never network snippets.
 *
 * The safety posture is the plugin's own stored snippets' (issue #85):
 *  - create-snippet stores a PHP snippet INACTIVE, and refuses code that
 *    fails Php_Snippet_Validator (an advisory speed-bump, not a security
 *    boundary) before anything is written;
 *  - update-snippet re-checks new code, and a code change leaves the
 *    snippet inactive. An active snippet is refused outright: Code Snippets
 *    re-runs an active snippet's code whenever it is saved, so it is
 *    switched off first;
 *  - deactivate-snippet is never gated, so an active snippet can always be
 *    switched off;
 *  - nothing here ever saves a snippet as active, so Code Snippets never
 *    runs its code on our behalf.
 * Only PHP snippets (global, admin, front-end and single-use scopes) are
 * written; HTML, CSS and JS snippets are listed and read only.
 *
 * Every write is snapshotted as a 'code_snippet' row image first
 * (Code_Snippet_Snapshot), so rollback-operation restores the row exactly,
 * except that an undo never leaves a snippet active.
 *
 * Every op takes manage_options and Code Snippets' own permission to manage
 * the site's snippets. Presence is filterable (wpmcp_code_snippets_active,
 * default: CODE_SNIPPETS_VERSION is defined); while Code Snippets is not
 * loaded the ops stay documented in list-operations (dependency_met:false)
 * and answer code_snippets_inactive.
 */
final class Code_Snippets_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Code Snippets' PHP scopes (Model\Snippet::get_all_scopes(), type php). */
    public const PHP_SCOPES = [ 'global', 'admin', 'front-end', 'single-use' ];

    private const MAX_PER_PAGE = 100;

    /** The Code Snippets API every op needs. */
    private const API = [ 'get_snippet', 'get_snippets', 'save_snippet', 'activate_snippet', 'deactivate_snippet', 'delete_snippet', 'clean_snippets_cache', 'code_snippets' ];

    /** Whether Code Snippets is loaded (filterable). */
    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_code_snippets_active', defined('CODE_SNIPPETS_VERSION'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        if (! self::active()) {
            return [ 'code' => 'code_snippets_inactive', 'message' => 'The Code Snippets plugin is not active on this site.' ];
        }
        foreach (self::API as $fn) {
            if (! function_exists('Code_Snippets\\' . $fn)) {
                return [ 'code' => 'code_snippets_unsupported', 'message' => 'This Code Snippets version lacks the API these operations use (Code Snippets 3.6 or newer).' ];
            }
        }
        if (! class_exists('Code_Snippets\Model\Snippet')) {
            return [ 'code' => 'code_snippets_unsupported', 'message' => 'This Code Snippets version lacks the API these operations use (Code Snippets 3.6 or newer).' ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base    = [
            'capability' => 'manage_options',
            'requires'   => static fn () => self::requirement(),
        ];
        $id_only = [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'id' ],
        ];
        $row     = static fn (array $args): array => [ 'object_type' => Code_Snippet_Snapshot::TYPE, 'object_id' => (int) $args['id'] ];

        return [
            'code-snippets-list-snippets'      => $base + [
                'mode'         => 'read',
                'description'  => 'List Code Snippets snippets (id, name, type, scope, status, tags, priority; no code); filter by scope, status or tag',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'scope'    => [ 'type' => 'string', 'maxLength' => 20 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'trashed' ] ],
                        'tag'      => [ 'type' => 'string', 'maxLength' => 100 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::access_refusal(),
                'handler'      => static fn (array $args): array => self::list_snippets($args),
            ],
            'code-snippets-get-snippet'        => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Code Snippets snippet with its code and, for PHP, a static check report (advisory)',
                'input_schema' => $id_only,
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::missing_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::get((int) $args['id']),
            ],
            'code-snippets-create-snippet'     => $base + [
                'mode'         => 'write',
                'description'  => 'Create a PHP snippet in Code Snippets, always inactive; code failing the static check is refused. Snapshotted; rollback deletes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'code'        => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'scope'       => [ 'type' => 'string', 'enum' => self::PHP_SCOPES ],
                        'tags'        => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 100 ] ],
                        'priority'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 32767 ],
                    ],
                    'required'   => [ 'name', 'code' ],
                ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::code_refusal((string) $args['code'], 'create the snippet'),
                'snapshot'     => static fn (): array => [ 'object_type' => Code_Snippet_Snapshot::TYPE, 'object_id' => 0 ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, (string) ($context['operation_id'] ?? '')),
            ],
            'code-snippets-update-snippet'     => $base + [
                'mode'         => 'write',
                'description'  => 'Change an inactive PHP snippet\'s name, description, code, tags or priority; new code must pass the static check. Active snippets are refused (deactivate first). Snapshotted',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'code'        => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'tags'        => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 100 ] ],
                        'priority'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 32767 ],
                    ],
                    'required'      => [ 'id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $row,
                'handler'      => static fn (array $args, array $context): array => self::update($args, (string) ($context['operation_id'] ?? '')),
            ],
            'code-snippets-activate-snippet'   => Code_Snippets_Activation::definition($base, $id_only, $row),
            'code-snippets-deactivate-snippet' => $base + [
                'mode'         => 'write',
                'description'  => 'Deactivate a Code Snippets snippet (never gated, so a snippet can always be switched off). Snapshotted',
                'input_schema' => $id_only,
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::missing_refusal((int) $args['id']) ?? self::state_refusal((int) $args['id'], true),
                'snapshot'     => $row,
                'handler'      => static fn (array $args, array $context): array => self::deactivate((int) $args['id'], (string) ($context['operation_id'] ?? '')),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    private static function list_snippets(array $args): array
    {
        $rows = [];
        foreach (\Code_Snippets\get_snippets([], false) as $snippet) {
            $view = self::view($snippet);
            if (isset($args['scope']) && $view['scope'] !== (string) $args['scope']) {
                continue;
            }
            $status = (string) ($args['status'] ?? '');
            if ('' === $status ? 'trashed' === $view['status'] : $view['status'] !== $status) {
                continue;
            }
            if (isset($args['tag']) && ! in_array((string) $args['tag'], $view['tags'], true)) {
                continue;
            }
            $rows[] = $view;
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $per  = (int) ($args['per_page'] ?? 50);
        $page = (int) ($args['page'] ?? 1);
        return [
            'snippets' => array_slice($rows, ($page - 1) * $per, $per),
            'total'    => count($rows),
            'page'     => $page,
            'per_page' => $per,
        ];
    }

    private static function get(int $id): array
    {
        $snippet = self::find($id);
        $code    = (string) $snippet->code;
        $out     = [ 'snippet' => self::view($snippet) + [ 'description' => (string) $snippet->desc, 'code' => $code ] ];
        if ('php' === (string) $snippet->type) {
            $out['validation'] = Php_Snippet_Validator::validate($code);
        }
        return $out;
    }

    /** A snippet as returned: no code. */
    public static function view(object $snippet): array
    {
        $status = ! empty($snippet->trashed) ? 'trashed' : (! empty($snippet->active) ? 'active' : 'inactive');
        return [
            'id'       => (int) $snippet->id,
            'name'     => (string) $snippet->name,
            'type'     => (string) $snippet->type,
            'scope'    => (string) $snippet->scope,
            'status'   => $status,
            'tags'     => array_values(array_map('strval', (array) $snippet->tags)),
            'priority' => (int) $snippet->priority,
            'locked'   => ! empty($snippet->locked),
            'modified' => (string) $snippet->modified,
        ];
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    private static function create(array $args, string $operation): array
    {
        $snippet = new \Code_Snippets\Model\Snippet(
            [
                'name'     => sanitize_text_field((string) $args['name']),
                'desc'     => wp_kses_post((string) ($args['description'] ?? '')),
                'code'     => (string) $args['code'],
                'scope'    => (string) ($args['scope'] ?? 'global'),
                'tags'     => array_values(array_map('sanitize_text_field', (array) ($args['tags'] ?? []))),
                'priority' => (int) ($args['priority'] ?? 10),
                'active'   => false,
                'network'  => false,
            ]
        );
        $saved = \Code_Snippets\save_snippet($snippet);
        $id    = is_object($saved) ? (int) $saved->id : 0;
        if ($id <= 0) {
            self::void($operation);
            throw new Operation_Refused('write_failed', 'Code Snippets did not save the snippet.');
        }
        if ('' !== $operation) {
            Snapshot_Store::update_snapshot($operation, Code_Snippet_Snapshot::created($id));
        }
        $stored = self::find($id);
        return [ 'snippet' => self::view($stored), 'validation' => Php_Snippet_Validator::validate((string) $stored->code) ];
    }

    private static function update(array $args, string $operation): array
    {
        $id      = (int) $args['id'];
        $snippet = self::find($id);
        // Re-checked at write time: an active snippet is re-run by
        // save_snippet(), and one may have been switched on since validate().
        if (! empty($snippet->active)) {
            self::void($operation);
            throw new Operation_Refused('snippet_active', 'The snippet was activated meanwhile; deactivate it before changing it.');
        }
        if (isset($args['name'])) {
            $snippet->name = sanitize_text_field((string) $args['name']);
        }
        if (isset($args['description'])) {
            $snippet->desc = wp_kses_post((string) $args['description']);
        }
        if (isset($args['code'])) {
            $snippet->code = (string) $args['code'];
        }
        if (isset($args['tags'])) {
            $snippet->tags = array_values(array_map('sanitize_text_field', (array) $args['tags']));
        }
        if (isset($args['priority'])) {
            $snippet->priority = (int) $args['priority'];
        }
        $snippet->active  = false;
        $snippet->network = false;
        $saved            = \Code_Snippets\save_snippet($snippet);
        if (! is_object($saved) || (int) $saved->id !== $id) {
            self::void($operation);
            throw new Operation_Refused('write_failed', 'Code Snippets did not save the snippet.');
        }
        $stored = self::find($id);
        return [ 'snippet' => self::view($stored), 'validation' => Php_Snippet_Validator::validate((string) $stored->code) ];
    }

    private static function deactivate(int $id, string $operation): array
    {
        $out = \Code_Snippets\deactivate_snippet($id, false);
        if (! is_object($out)) {
            self::void($operation);
            throw new Operation_Refused('write_failed', 'Code Snippets did not deactivate the snippet.');
        }
        return [ 'snippet' => self::view(self::find($id)) ];
    }

    // -----------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------

    /** The snippet with this id, read through Code Snippets (fresh), or null. */
    public static function lookup(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        \Code_Snippets\clean_snippets_cache(Code_Snippet_Snapshot::table());
        $snippet = \Code_Snippets\get_snippet($id, false);
        return is_object($snippet) && (int) $snippet->id === $id ? $snippet : null;
    }

    public static function find(int $id): object
    {
        $snippet = self::lookup($id);
        if (null === $snippet) {
            throw new Operation_Refused('snippet_not_found', sprintf('No Code Snippets snippet with id %d.', $id));
        }
        return $snippet;
    }

    /** Refusal when the caller may not manage the site's snippets in Code Snippets, or null. */
    public static function access_refusal(): ?array
    {
        $plugin = \Code_Snippets\code_snippets();
        if (method_exists($plugin, 'user_can_manage_snippets') && ! $plugin->user_can_manage_snippets(get_current_user_id(), false)) {
            return [ 'code' => 'operation_denied', 'message' => 'You are not allowed to manage this site\'s snippets in Code Snippets.', 'data' => [ 'reason' => 'capability' ] ];
        }
        return null;
    }

    public static function missing_refusal(int $id): ?array
    {
        return null === self::lookup($id)
            ? [ 'code' => 'snippet_not_found', 'message' => sprintf('No Code Snippets snippet with id %d.', $id), 'data' => [ 'id' => $id ] ]
            : null;
    }

    /** Refusal for a snippet that is not active ($want_active) or already active, or null. */
    public static function state_refusal(int $id, bool $want_active): ?array
    {
        $snippet = self::lookup($id);
        if (null === $snippet) {
            return null;
        }
        if (! empty($snippet->trashed)) {
            return [ 'code' => 'snippet_trashed', 'message' => sprintf('Snippet %d is in the Code Snippets trash.', $id), 'data' => [ 'id' => $id ] ];
        }
        if ($want_active && empty($snippet->active)) {
            return [ 'code' => 'snippet_inactive', 'message' => sprintf('Snippet %d is not active.', $id), 'data' => [ 'id' => $id ] ];
        }
        if (! $want_active && ! empty($snippet->active)) {
            return [ 'code' => 'snippet_active', 'message' => sprintf('Snippet %d is active; deactivate it first.', $id), 'data' => [ 'id' => $id ] ];
        }
        return null;
    }

    /** Refusal for a snippet these ops do not write (not PHP, or locked), or null. */
    public static function writable_refusal(int $id): ?array
    {
        $snippet = self::lookup($id);
        if (null === $snippet) {
            return null;
        }
        if ('php' !== (string) $snippet->type) {
            return [ 'code' => 'unsupported_snippet_type', 'message' => sprintf('Snippet %d is a %s snippet; only PHP snippets are written here.', $id, (string) $snippet->type), 'data' => [ 'id' => $id ] ];
        }
        if (! empty($snippet->locked)) {
            return [ 'code' => 'snippet_locked', 'message' => sprintf('Snippet %d is locked in Code Snippets.', $id), 'data' => [ 'id' => $id ] ];
        }
        return null;
    }

    /** Refusal when PHP code is too large or fails the static check, or null. */
    public static function code_refusal(string $code, string $what): ?array
    {
        if (strlen($code) > Php_Snippet_Store::max_code_bytes()) {
            return [ 'code' => 'code_too_large', 'message' => sprintf('Refusing to %s: the code is larger than %d bytes.', $what, Php_Snippet_Store::max_code_bytes()), 'data' => [] ];
        }
        $validation = Php_Snippet_Validator::validate($code);
        if ($validation['syntax_valid'] && $validation['safe']) {
            return null;
        }
        return [
            'code'    => 'validation_failed',
            'message' => sprintf('Refusing to %s: the code does not pass static validation (advisory speed-bump, not a security boundary).', $what),
            'data'    => [ 'validation' => $validation ],
        ];
    }

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        return self::access_refusal()
            ?? self::missing_refusal($id)
            ?? self::writable_refusal($id)
            ?? self::state_refusal($id, false)
            ?? (isset($args['code']) ? self::code_refusal((string) $args['code'], 'update the snippet') : null);
    }

    /** Void this operation's undo point: the write it guarded did not happen. */
    public static function void(string $operation): void
    {
        if ('' !== $operation) {
            Snapshot_Store::delete_operation($operation);
        }
    }
}
