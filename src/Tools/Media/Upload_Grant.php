<?php

namespace WPMCP\Tools\Media;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single-use upload links for upload-media grant:true (issue #471), so a
 * large file (a video, a brochure PDF) reaches the Media Library without its
 * bytes passing through the model as base64.
 *
 * issue() runs inside the tool call and stores one grant: the calling user,
 * the already-checked filename, the attachment fields and an expiry a few
 * minutes out. The client then PUTs (or POSTs) the raw bytes to
 * /wpmcp/v1/media-upload/<token>, and handle_request() turns them into an
 * attachment through Upload_Media::store(), the same size cap, byte
 * sniffing, blocked types and 'media_import' snapshot as a base64 upload.
 *
 * The 192-bit random token in the URL is the only credential the route
 * takes, so the abuse cases are handled here:
 *  - stored hashed: the options row is keyed by the token's SHA-256, so a
 *    database read never yields a usable link;
 *  - single use: the first request that reaches a grant deletes its row
 *    and only the request whose DELETE removed it may continue, so two
 *    racing requests cannot both upload. Any attempt spends the grant, a
 *    refused one included, so a link cannot be probed;
 *  - bound to the user: the attachment is created as the granting user,
 *    who must still have upload_files when the bytes arrive; a request
 *    signed in as anyone else is refused (and burns the link);
 *  - short-lived: 5 minutes by default (wpmcp_upload_grant_ttl), clamped to
 *    30 seconds .. 15 minutes; expired rows are purged on every new grant;
 *  - one bounded body: the declared Content-Length and the actual body are
 *    both held to the site's upload limit (which on multisite already
 *    includes the remaining quota), a body shorter than its declared length
 *    is refused as incomplete, multipart bodies are refused, and the temp
 *    file is always deleted unless it became the attachment;
 *  - audited: issuing, using and every refusal of a real grant land in the
 *    governance audit log. Unknown tokens are not logged, so anonymous
 *    probing cannot push real entries out of the capped log.
 *
 * The route is deliberately NOT on Transport_Guard's guarded surface: that
 * surface is also where a gateway credential may authenticate, and this
 * route needs no credential but the token. Its responses still carry the
 * no-store headers.
 */
class Upload_Grant
{
    public const NAMESPACE     = 'wpmcp/v1';
    public const ROUTE         = '/media-upload/(?P<token>[a-f0-9]{48})';
    public const OPTION_PREFIX = 'wpmcp_upload_grant_';

    private const ABILITY     = 'wpmcp/upload-media';
    private const DEFAULT_TTL = 300;
    private const MIN_TTL     = 30;
    private const MAX_TTL     = 900;
    private const PURGE_BATCH = 100;

    /** Attachment fields a grant carries from the tool call to the upload. */
    private const CARRIED_ARGS = ['title', 'alt', 'caption', 'post_id', 'session_id'];

    private static ?int $clock = null;

    public static function set_clock_for_tests(?int $timestamp): void
    {
        self::$clock = $timestamp;
    }

    private static function now(): int
    {
        return self::$clock ?? time();
    }

    public static function ttl(): int
    {
        $ttl = (int) apply_filters('wpmcp_upload_grant_ttl', self::DEFAULT_TTL);
        return max(self::MIN_TTL, min(self::MAX_TTL, $ttl));
    }

    /**
     * Store a grant for the current user and return the link to send the
     * file to. $filename has already passed Upload_Media::filename().
     *
     * @return array{upload_url: string, method: string, expires_at: string, expires_in: int, max_bytes: int, curl: string}
     * @throws \RuntimeException when there is no user who may upload files.
     */
    public static function issue(string $filename, array $args): array
    {
        $user_id = get_current_user_id();
        if ($user_id < 1 || ! user_can($user_id, 'upload_files')) {
            throw new \RuntimeException('An upload link needs a signed-in user who can upload files.');
        }

        self::purge_expired();

        $token   = bin2hex(random_bytes(24));
        $ttl     = self::ttl();
        $expires = self::now() + $ttl;
        $stored  = add_option(self::option_name($token), [
            'user_id'  => $user_id,
            'filename' => $filename,
            'expires'  => $expires,
            'args'     => array_intersect_key($args, array_flip(self::CARRIED_ARGS)),
        ], '', false);
        if (! $stored) {
            throw new \RuntimeException('The upload link could not be stored.');
        }

        Governance_Audit_Log::record_quietly(self::ABILITY, true, 'upload-grant:issued');

        $url = rest_url(self::NAMESPACE . '/media-upload/' . $token);

        return [
            'upload_url' => $url,
            'method'     => 'PUT',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'expires_in' => $ttl,
            'max_bytes'  => Upload_Media::max_bytes(),
            'curl'       => sprintf('curl -sS -T %s %s', escapeshellarg($filename), escapeshellarg($url)),
        ];
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => ['PUT', 'POST'],
            // The token in the URL is the credential; handle_request()
            // checks it, the bound user and their capability.
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'handle_request'],
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle_request(\WP_REST_Request $request)
    {
        $grant = self::claim((string) $request->get_param('token'));
        if (null === $grant) {
            return self::error('wpmcp_upload_grant_invalid', 'This upload link is unknown or was already used. Ask upload-media for a new one (grant: true).', 404);
        }

        $user_id = (int) ($grant['user_id'] ?? 0);
        if (self::now() > (int) ($grant['expires'] ?? 0)) {
            return self::refuse('expired', 'This upload link has expired. Ask upload-media for a new one (grant: true).', 410);
        }
        $current = get_current_user_id();
        if ($current > 0 && $current !== $user_id) {
            return self::refuse('wrong-user', 'This upload link belongs to another user; it is no longer valid.', 403);
        }
        if ($user_id < 1 || ! user_can($user_id, 'upload_files')) {
            return self::refuse('no-capability', 'The user this upload link was issued to can no longer upload files.', 403);
        }

        $type = $request->get_content_type();
        if (is_array($type) && str_starts_with(strtolower((string) ($type['value'] ?? '')), 'multipart/')) {
            return self::refuse('rejected', 'Send the raw file bytes as the request body (curl -T file URL), not a multipart form.', 415);
        }

        $max      = Upload_Media::max_bytes();
        $declared = trim((string) $request->get_header('content_length'));
        if ('' !== $declared && (int) $declared > $max) {
            return self::refuse('rejected', sprintf('The file declares %d bytes, above the %d byte upload limit.', (int) $declared, (int) $max), 413);
        }

        $body = $request->get_body();
        $size = strlen($body);
        if ($size < 1) {
            return self::refuse('rejected', 'The request body is empty; send the file bytes.', 400);
        }
        if ($size > $max) {
            return self::refuse('rejected', sprintf('The file is %d bytes, above the %d byte upload limit.', (int) $size, (int) $max), 413);
        }
        if ('' !== $declared && (int) $declared !== $size) {
            return self::refuse('rejected', sprintf('The upload is incomplete: %d of %d declared bytes arrived.', (int) $size, (int) $declared), 400);
        }

        $previous = get_current_user_id();
        $tmp      = '';
        wp_set_current_user($user_id);
        try {
            $media = new Upload_Media();
            // Re-checked so a grant row can never smuggle in a name the tool refuses.
            $filename = $media->filename((string) ($grant['filename'] ?? ''));

            if (! function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = wp_tempnam('wpmcp-upload-grant', Upload_Media::temp_dir());
            if (false === file_put_contents($tmp, $body)) {
                throw new \RuntimeException('The upload could not be written to a temporary file.');
            }
            unset($body);

            $args = (array) ($grant['args'] ?? []);
            $out  = $media->store($tmp, $filename, $args, $args + ['grant' => true, 'filename' => $filename]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return self::refuse('rejected', $e->getMessage(), 400);
        } finally {
            wp_set_current_user($previous);
            if ('' !== $tmp && is_file($tmp)) {
                wp_delete_file($tmp);
            }
        }

        Governance_Audit_Log::record_quietly(self::ABILITY, true, 'upload-grant:used');

        $response = new \WP_REST_Response($out, 201);
        $response->set_headers(Transport_Guard::no_store_headers());
        return $response;
    }

    /**
     * Take the grant for $token out of the store. Returns it only to the one
     * request whose DELETE removed the row, so the grant is spent exactly
     * once even when requests race.
     *
     * @return array<string, mixed>|null
     */
    private static function claim(string $token): ?array
    {
        global $wpdb;

        if (1 !== preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $name = self::option_name($token);

        // Read and delete the row directly: the options cache could hand two
        // requests the same value, and only the DELETE's row count says who won.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-use claim must bypass the options cache.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (null === $raw) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the affected-row count is the single-use lock.
        $deleted = $wpdb->delete($wpdb->options, ['option_name' => $name]);
        wp_cache_delete($name, 'options');
        if (1 !== (int) $deleted) {
            return null;
        }

        $grant = maybe_unserialize($raw);
        return is_array($grant) ? $grant : null;
    }

    /** Delete grants that expired without being used. */
    private static function purge_expired(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prefix scan of this plugin's own non-autoloaded rows.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
            $wpdb->esc_like(self::OPTION_PREFIX) . '%',
            self::PURGE_BATCH
        ));

        foreach ((array) $rows as $row) {
            $grant = maybe_unserialize($row->option_value);
            if (! is_array($grant) || self::now() > (int) ($grant['expires'] ?? 0)) {
                delete_option((string) $row->option_name);
            }
        }
    }

    private static function option_name(string $token): string
    {
        return self::OPTION_PREFIX . hash('sha256', $token);
    }

    private static function refuse(string $reason, string $message, int $status): \WP_Error
    {
        Governance_Audit_Log::record_quietly(self::ABILITY, false, 'upload-grant:' . $reason);
        return self::error('wpmcp_upload_grant_' . str_replace('-', '_', $reason), $message, $status);
    }

    private static function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
