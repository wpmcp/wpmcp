<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Redirection and Rank Math redirect providers for the generic redirect
 * tools (issue #930), registered on wpmcp_redirect_providers (see
 * Tools\Redirects\Redirect_Router).
 *
 * Each route maps the generic arguments onto that plugin's own op on the
 * theme dispatcher pair and runs it there, so the call keeps the op's
 * capability check, opt-in, validation, loop and duplicate guards and its
 * snapshot: rollback-operation undoes a routed write exactly as it undoes
 * the plugin-specific op. A destructive op still wants confirm:true.
 *
 * Mapping, decided conservatively:
 *  - target_post_id becomes the post's current permalink path, since neither
 *    plugin follows a post id; the answer says so in 'note';
 *  - a field the plugin has nowhere to store (notes on Rank Math) is refused,
 *    never dropped;
 *  - Redirection enables and disables through their own ops, so an update
 *    that changes enabled together with other fields is refused and asks for
 *    two calls, keeping one snapshot per call.
 * Anything only one plugin supports (groups, error actions, comparisons, the
 * 404 log) stays on that plugin's own ops.
 */
final class Redirect_Providers
{
    /**
     * @param array<string,mixed> $providers
     * @return array<string,mixed>
     */
    public static function register($providers): array
    {
        $providers = is_array($providers) ? $providers : [];

        $providers['redirection'] = [
            'label'  => 'Redirection',
            'active' => static fn (): bool => Redirection_Pack::is_active(),
            'route'  => static fn (string $tool, array $args) => self::redirection($tool, $args),
        ];
        $providers['rankmath']    = [
            'label'  => 'Rank Math',
            'active' => static fn (): bool => Rank_Math_Redirects_Pack::is_active()
                && in_array('redirections', (array) get_option('rank_math_modules', []), true),
            'route'  => static fn (string $tool, array $args) => self::rank_math($tool, $args),
        ];

        return $providers;
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function redirection(string $tool, array $args)
    {
        switch ($tool) {
            case 'list-redirects':
                $query = self::paging($args);
                if (isset($args['enabled'])) {
                    $query['status'] = $args['enabled'] ? 'enabled' : 'disabled';
                }
                if (isset($args['search'])) {
                    $query['search'] = (string) $args['search'];
                }
                return self::run('read', 'list-redirection-redirects', $query, $args);

            case 'create-redirect':
                $target = self::target($args);
                if ($target instanceof \WP_Error) {
                    return $target;
                }
                $fields = self::common_fields($args, $target[0]) + [ 'action_type' => 'url' ];
                if (isset($args['status_code'])) {
                    $fields['action_code'] = (int) $args['status_code'];
                }
                if (isset($args['notes'])) {
                    $fields['title'] = (string) $args['notes'];
                }
                if (isset($args['enabled'])) {
                    $fields['enabled'] = (bool) $args['enabled'];
                }
                return self::with_note(self::run('write', 'create-redirection-redirect', $fields, $args), $target[1]);

            case 'update-redirect':
                $id     = (int) ($args['redirect_id'] ?? 0);
                $target = self::target($args);
                if ($target instanceof \WP_Error) {
                    return $target;
                }
                $fields = self::common_fields($args, $target[0]);
                if (isset($args['status_code'])) {
                    $fields['action_code'] = (int) $args['status_code'];
                }
                if (isset($args['notes'])) {
                    $fields['title'] = (string) $args['notes'];
                }
                if (isset($args['enabled'])) {
                    if ([] !== $fields) {
                        return new \WP_Error('redirect_update_split', 'Redirection enables and disables a redirect in its own operation: change enabled in one call and the other fields in another.');
                    }
                    return self::run('write', $args['enabled'] ? 'enable-redirection-redirect' : 'disable-redirection-redirect', [ 'id' => $id ], $args);
                }
                return self::with_note(self::run('write', 'update-redirection-redirect', [ 'id' => $id ] + $fields, $args), $target[1]);

            case 'delete-redirect':
                return self::run('write', 'delete-redirection-redirect', [ 'id' => (int) ($args['redirect_id'] ?? 0) ], $args);
        }

        return new \WP_Error('redirect_tool_unknown', sprintf('No Redirection route for %s.', esc_html($tool)));
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function rank_math(string $tool, array $args)
    {
        if (isset($args['notes']) && in_array($tool, [ 'create-redirect', 'update-redirect' ], true)) {
            return new \WP_Error('redirect_field_unsupported', 'Rank Math redirections have no notes field; leave notes out (or pass provider:"wpmcp" to use WP MCP\'s own redirect table).', [ 'field' => 'notes' ]);
        }

        switch ($tool) {
            case 'list-redirects':
                $query = self::paging($args);
                if (isset($args['enabled'])) {
                    $query['status'] = $args['enabled'] ? 'active' : 'inactive';
                }
                if (isset($args['search'])) {
                    $query['search'] = (string) $args['search'];
                }
                $out = self::run('read', 'rankmath-list-redirections', $query, $args);
                if (is_array($out) && isset($out['redirections'])) {
                    $out['redirects'] = $out['redirections'];
                    unset($out['redirections']);
                }
                return $out;

            case 'create-redirect':
            case 'update-redirect':
                $target = self::target($args);
                if ($target instanceof \WP_Error) {
                    return $target;
                }
                $fields = self::common_fields($args, $target[0]);
                if (isset($args['status_code'])) {
                    $fields['header_code'] = (int) $args['status_code'];
                }
                if (isset($args['enabled'])) {
                    $fields['status'] = $args['enabled'] ? 'active' : 'inactive';
                }
                if ('create-redirect' === $tool) {
                    return self::with_note(self::run('write', 'rankmath-create-redirection', $fields, $args), $target[1]);
                }
                return self::with_note(self::run('write', 'rankmath-update-redirection', [ 'id' => (int) ($args['redirect_id'] ?? 0) ] + $fields, $args), $target[1]);

            case 'delete-redirect':
                return self::run('write', 'rankmath-delete-redirection', [ 'id' => (int) ($args['redirect_id'] ?? 0) ], $args);
        }

        return new \WP_Error('redirect_tool_unknown', sprintf('No Rank Math route for %s.', esc_html($tool)));
    }

    /** @return array<string,int> per_page and page from the generic limit and offset. */
    private static function paging(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['limit'] ?? 100)));
        $offset   = max(0, (int) ($args['offset'] ?? 0));
        return [ 'per_page' => $per_page, 'page' => intdiv($offset, $per_page) + 1 ];
    }

    /** @return array<string,mixed> source and target, when passed. */
    private static function common_fields(array $args, ?string $target): array
    {
        $fields = [];
        if (isset($args['source'])) {
            $fields['source'] = (string) $args['source'];
        }
        if (null !== $target) {
            $fields['target'] = $target;
        }
        return $fields;
    }

    /**
     * The target to send (null when none was passed) and a note when it came
     * from a post id.
     *
     * @return array{0:?string,1:string}|\WP_Error
     */
    private static function target(array $args)
    {
        $post_id = (int) ($args['target_post_id'] ?? 0);
        if ($post_id > 0) {
            if (isset($args['target']) && '' !== (string) $args['target']) {
                return new \WP_Error('invalid_redirect_target', 'Pass target or target_post_id, not both.');
            }
            $link = 'publish' === get_post_status($post_id) ? get_permalink($post_id) : false;
            if (! is_string($link) || '' === $link) {
                return new \WP_Error('invalid_redirect_target', sprintf('Post %d is not a published post with a permalink.', $post_id));
            }
            return [ wp_make_link_relative($link), 'target_post_id was stored as the post\'s current permalink path; this plugin does not follow later slug changes.' ];
        }
        return [ isset($args['target']) ? (string) $args['target'] : null, '' ];
    }

    /**
     * @param array<string,mixed>|\WP_Error $out
     * @return array<string,mixed>|\WP_Error
     */
    private static function with_note($out, string $note)
    {
        if (is_array($out) && '' !== $note) {
            $out['note'] = $note;
        }
        return $out;
    }

    /**
     * Run one op on the theme pair and unwrap its envelope.
     *
     * @return array<string,mixed>|\WP_Error
     */
    private static function run(string $channel, string $op, array $op_args, array $args)
    {
        $call = [ 'operation' => $op, 'args' => $op_args ];
        if (isset($args['session_id'])) {
            $call['session_id'] = (string) $args['session_id'];
        }
        if (true === ($args['confirm'] ?? false)) {
            $call['confirm'] = true;
        }

        $pair = new Theme_Integration();
        $out  = 'read' === $channel ? $pair->handle_read($call) : $pair->handle_write($call);

        if (isset($out['error']['code'])) {
            return new \WP_Error((string) $out['error']['code'], (string) ($out['error']['message'] ?? ''), (array) ($out['error']['data'] ?? []) + [ 'operation' => $op ]);
        }

        $result = is_array($out['result'] ?? null) ? $out['result'] : [ 'result' => $out['result'] ?? null ];
        $extra  = array_intersect_key($out, [ 'operation_id' => true, 'operation_ids' => true, 'recoverable' => true ]);

        return [ 'routed_operation' => $op ] + $result + $extra;
    }
}
