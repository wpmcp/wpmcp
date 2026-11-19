<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core personal data requests (issue #714): the requests behind
 * Tools > Export Personal Data and Tools > Erase Personal Data, as paid-tier
 * ops on the theme dispatcher pair next to the other core settings packs,
 * so the feature adds no top-level tool. Core keeps each request as a
 * user_request post: the email in post_title, the action
 * (export_personal_data or remove_personal_data) in post_name and the state
 * in post_status (request-pending, request-confirmed, request-failed,
 * request-completed).
 *
 * Reads: list-privacy-requests (emails masked through Masking) and
 * get-privacy-request (one request in full, never its confirmation key).
 * Both run at manage_options and show only the request types the caller
 * may handle: export_others_personal_data for exports and
 * erase_others_personal_data for erasures, which core gives a single site's
 * administrators and only super admins on a network.
 *
 * Writes, all off until the site opts in through the
 * wpmcp_enable_core_privacy_write filter, all undoable:
 *  - create-export-request and create-erasure-request file a request the
 *    way the core screens do: with send_email:true the requester gets the
 *    confirmation email and the request waits as pending; without it the
 *    administrator vouches for it and it is filed as confirmed. The creation
 *    is recorded, so rollback-session trashes the request (an email already
 *    sent stays sent, which the result says).
 *  - create-erasure-request needs confirm:true in its own arguments, after
 *    a preview naming the email, the matching user and the registered
 *    erasers. It files the request only: no op here runs an exporter or an
 *    eraser, so nothing is erased through this pack.
 *  - mark-request-complete sets the request to completed with its
 *    completion time, as core's "Mark as complete" does, under a post
 *    snapshot, so rollback-operation puts the status and meta back.
 */
final class Core_Privacy_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** The site-wide opt-in for every write op of this pack. */
    public const ENABLE_FILTER = 'wpmcp_enable_core_privacy_write';

    private const POST_TYPE = 'user_request';

    private const MAX_PER_PAGE = 100;

    /** Our type name => [core action name, the capability core's screen takes]. */
    private const TYPES = [
        'export'  => [ 'export_personal_data', 'export_others_personal_data' ],
        'erasure' => [ 'remove_personal_data', 'erase_others_personal_data' ],
    ];

    private const STATUSES = [ 'pending', 'confirmed', 'failed', 'completed' ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_core_privacy_write', false);
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $create  = [
            'type'       => 'object',
            'properties' => [
                'email'      => [ 'type' => 'string', 'format' => 'email', 'maxLength' => 100 ],
                'send_email' => [ 'type' => 'boolean' ],
            ],
            'required'   => [ 'email' ],
        ];
        $write   = [
            'tier'               => 'pro',
            'capability'         => 'manage_options',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];

        return [
            'list-privacy-requests'  => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'List personal data export and erasure requests (Tools > Export or Erase Personal Data), newest first, with type, status, user id and created, confirmed and completed times (UTC). Emails are masked; get-privacy-request returns one in full. Filter by type (export, erasure) and status (pending, confirmed, failed, completed)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => [ 'type' => 'string', 'enum' => array_keys(self::TYPES) ],
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::type_refusal(isset($args['type']) ? (string) $args['type'] : null),
                'handler'      => static fn (array $args): array => self::list_requests($args),
            ],
            'get-privacy-request'    => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Read one personal data request in full: email, type, status, the matching user, the request data and its created, confirmed and completed times (UTC). The confirmation key is never returned',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'request_id' => $id ], 'required' => [ 'request_id' ] ],
                'validate'     => static fn (array $args): ?array => self::request_refusal((int) $args['request_id']),
                'handler'      => static fn (array $args): array => self::full((int) $args['request_id']),
            ],
            'create-export-request'  => [
                'mode'              => 'write',
                'self_snapshotting' => true,
                'description'       => 'File a personal data export request for an email address, as Tools > Export Personal Data does. send_email:true mails the requester the confirmation link and leaves the request pending; without it the request is filed as confirmed. Refuses an incomplete request already on file for that email. Undo: rollback-session trashes the request (a sent email stays sent). Disabled by default (wpmcp_enable_core_privacy_write filter)',
                'input_schema'      => $create,
                'validate'          => static fn (array $args): ?array => self::create_refusal('export', (string) $args['email']),
                'handler'           => static fn (array $args, array $context): array => self::create('export', $args, $context),
            ] + $write,
            'create-erasure-request' => [
                'mode'                 => 'destructive',
                'self_snapshotting'    => true,
                'confirm_in_arguments' => true,
                'description'          => 'File a personal data erasure request for an email address, as Tools > Erase Personal Data does. It erases nothing itself: an administrator runs the erasure from that screen once the request is confirmed. Needs confirm:true in the call\'s own arguments; the refusal shows the email, the matching user and the registered erasers first. send_email:true mails the confirmation link and leaves it pending; without it the request is filed as confirmed. Undo: rollback-session trashes the request. Disabled by default (wpmcp_enable_core_privacy_write filter)',
                'input_schema'         => $create,
                'validate'             => static fn (array $args): ?array => self::create_refusal('erasure', (string) $args['email']),
                'confirm_preview'      => static fn (array $args): array => self::erasure_preview($args),
                'handler'              => static fn (array $args, array $context): array => self::create('erasure', $args, $context),
            ] + $write,
            'mark-request-complete'  => [
                'mode'         => 'write',
                'description'  => 'Mark a personal data request completed with the current time, as core\'s "Mark as complete" does. Refuses a request that is already completed. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_core_privacy_write filter)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'request_id' => $id ], 'required' => [ 'request_id' ] ],
                'validate'     => static fn (array $args): ?array => self::complete_refusal((int) $args['request_id']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['request_id'] ],
                'handler'      => static fn (array $args): array => self::complete((int) $args['request_id']),
            ] + $write,
        ];
    }

    // ---------------------------------------------------------------
    // Types and access
    // ---------------------------------------------------------------

    /** @return string[] the types the current user may handle. */
    private static function allowed_types(): array
    {
        return array_values(array_filter(array_keys(self::TYPES), static fn (string $type): bool => current_user_can(self::TYPES[ $type ][1])));
    }

    private static function type_of(string $action_name): ?string
    {
        foreach (self::TYPES as $type => [ $action ]) {
            if ($action === $action_name) {
                return $type;
            }
        }
        return null;
    }

    private static function type_refusal(?string $type): ?array
    {
        $allowed = self::allowed_types();
        if ([] === $allowed || (null !== $type && ! in_array($type, $allowed, true))) {
            $cap = null === $type ? 'export_others_personal_data or erase_others_personal_data' : self::TYPES[ $type ][1];
            return self::refusal('operation_denied', sprintf('Handling personal data requests needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
        }
        return null;
    }

    private static function request(int $request_id): ?\WP_User_Request
    {
        $request = wp_get_user_request($request_id);
        return $request instanceof \WP_User_Request && null !== self::type_of((string) $request->action_name) ? $request : null;
    }

    private static function request_refusal(int $request_id): ?array
    {
        $request = self::request($request_id);
        if (null === $request) {
            return self::refusal('request_not_found', sprintf('Personal data request %d does not exist.', $request_id));
        }
        return self::type_refusal(self::type_of((string) $request->action_name));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    private static function list_requests(array $args): array
    {
        $types    = isset($args['type']) ? [ (string) $args['type'] ] : self::allowed_types();
        $statuses = isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES;
        $per_page = (int) ($args['per_page'] ?? 20);
        $page     = (int) ($args['page'] ?? 1);

        $query = new \WP_Query([
            'post_type'           => self::POST_TYPE,
            'post_status'         => array_map(static fn (string $s): string => 'request-' . $s, $statuses),
            'post_name__in'       => array_map(static fn (string $t): string => self::TYPES[ $t ][0], $types),
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'ID',
            'order'               => 'DESC',
            'no_found_rows'       => false,
            'ignore_sticky_posts' => true,
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            $request = self::request((int) $post->ID);
            if (null !== $request) {
                $row          = self::summary($request);
                $row['email'] = Masking::email((string) $row['email']);
                $rows[]       = $row;
            }
        }

        return [
            'requests' => $rows,
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
            'masked'   => true,
        ];
    }

    private static function summary(\WP_User_Request $request): array
    {
        return [
            'id'           => (int) $request->ID,
            'type'         => self::type_of((string) $request->action_name),
            'status'       => (string) preg_replace('/^request-/', '', (string) $request->status),
            'email'        => (string) $request->email,
            'user_id'      => (int) $request->user_id,
            'created_at'   => self::time($request->created_timestamp),
            'confirmed_at' => self::time($request->confirmed_timestamp),
            'completed_at' => self::time($request->completed_timestamp),
        ];
    }

    private static function full(int $request_id): array
    {
        $request = self::request($request_id);
        if (null === $request) {
            return [];
        }
        $out                 = self::summary($request);
        $out['user']         = self::user_summary((int) $request->user_id, (string) $request->email);
        $out['request_data'] = is_array($request->request_data) ? $request->request_data : [];
        return $out;
    }

    /** The account the request belongs to, by its stored id or else its email. */
    private static function user_summary(int $user_id, string $email): ?array
    {
        $user = $user_id > 0 ? get_userdata($user_id) : get_user_by('email', $email);
        if (! $user instanceof \WP_User) {
            return null;
        }
        return [
            'id'           => (int) $user->ID,
            'login'        => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'roles'        => array_values((array) $user->roles),
        ];
    }

    /** @param mixed $timestamp */
    private static function time($timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp) : null;
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    private static function create_refusal(string $type, string $email): ?array
    {
        $denied = self::type_refusal($type);
        if (null !== $denied) {
            return $denied;
        }
        $email = sanitize_email($email);
        if (! is_email($email)) {
            return self::refusal('invalid_email', 'Pass a valid email address.');
        }
        $existing = get_posts([
            'post_type'     => self::POST_TYPE,
            'post_name__in' => [ self::TYPES[ $type ][0] ],
            'title'         => $email,
            'post_status'   => [ 'request-pending', 'request-confirmed' ],
            'fields'        => 'ids',
            'numberposts'   => 1,
        ]);
        if ([] !== $existing) {
            return self::refusal('duplicate_request', sprintf('An incomplete %s request for this email address already exists (request %d). Complete it first.', $type, (int) $existing[0]), [ 'request_id' => (int) $existing[0] ]);
        }
        return null;
    }

    private static function erasure_preview(array $args): array
    {
        $email   = sanitize_email((string) $args['email']);
        $user    = self::user_summary(0, $email);
        $erasers = [];
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own eraser registry, read the way the Erase Personal Data screen reads it.
        foreach ((array) apply_filters('wp_privacy_personal_data_erasers', []) as $key => $eraser) {
            $erasers[] = (string) ($eraser['eraser_friendly_name'] ?? $key);
        }
        $send = ! empty($args['send_email']);

        return [
            'message' => sprintf(
                'Files an erasure request for %s (%s). %s It erases nothing itself: once confirmed, an administrator runs the erasure from Tools > Erase Personal Data, where these erasers remove or anonymize the data: %s.',
                $email,
                null === $user ? 'no user account' : sprintf('user %d, %s', $user['id'], $user['login']),
                $send ? 'The requester is emailed a confirmation link and the request waits as pending.' : 'No email is sent; the request is filed as confirmed.',
                [] === $erasers ? 'none registered' : implode(', ', $erasers)
            ),
            'data'    => [
                'email'      => $email,
                'user'       => $user,
                'erasers'    => $erasers,
                'send_email' => $send,
            ],
        ];
    }

    private static function create(string $type, array $args, array $context): array
    {
        $email = sanitize_email((string) $args['email']);
        $send  = ! empty($args['send_email']);
        $id    = wp_create_user_request($email, self::TYPES[ $type ][0], [], $send ? 'pending' : 'confirmed');
        if (is_wp_error($id)) {
            throw new Operation_Refused((string) $id->get_error_code(), $id->get_error_message());
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);

        $result = [ 'email_sent' => false ];
        if ($send) {
            $sent = wp_send_user_request($id);
            if (is_wp_error($sent)) {
                $result['email_error'] = $sent->get_error_message();
            } else {
                $result['email_sent'] = true;
                $result['note']       = 'The confirmation email cannot be unsent; a rollback only trashes the request.';
            }
        }
        $request = self::request($id);

        return [ 'result' => [ 'request' => null === $request ? [ 'id' => $id ] : self::summary($request) ] + $result, 'operation_ids' => [ $op ] ];
    }

    // ---------------------------------------------------------------
    // Complete
    // ---------------------------------------------------------------

    private static function complete_refusal(int $request_id): ?array
    {
        $refusal = self::request_refusal($request_id);
        if (null !== $refusal) {
            return $refusal;
        }
        if ('request-completed' === get_post_status($request_id)) {
            return self::refusal('no_change', sprintf('Request %d is already completed. Nothing was written and no undo point was taken.', $request_id));
        }
        return null;
    }

    private static function complete(int $request_id): array
    {
        update_post_meta($request_id, '_wp_user_request_completed_timestamp', time());
        $result = Save_Filters::set_post_status($request_id, 'request-completed', true);
        if (is_wp_error($result)) {
            throw new Operation_Refused('update_failed', $result->get_error_message());
        }
        clean_post_cache($request_id);
        $request = self::request($request_id);

        return [ 'request' => null === $request ? [ 'id' => $request_id ] : self::summary($request) ];
    }

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
