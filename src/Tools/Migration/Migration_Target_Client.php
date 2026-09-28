<?php

namespace WPMCP\Tools\Migration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HTTP client for a push target's wpmcp/receive-site-archive ability
 * (issue #191). The only outbound host is the target URL the operator
 * supplied; nothing else is contacted.
 *
 * The call goes to the target's core Abilities REST run endpoint, addressed
 * with ?rest_route= so it works whether or not the target has pretty
 * permalinks. Authentication is the target's own: an application password
 * (HTTP Basic, the path WordPress core validates for REST) or an MCP OAuth
 * bearer token issued by the target. Credentials are held in this object
 * for one push and never stored.
 *
 * https is required: the Authorization header carries a credential and the
 * body carries a database dump. A plain http:// target is refused unless
 * wpmcp_migration_allow_insecure_target says otherwise (local development).
 * Requests go through wp_safe_remote_post(), so loopback and private
 * addresses are refused by core unless the site allows them with core's
 * http_request_host_is_external filter.
 */
class Migration_Target_Client
{
    public const ROUTE = '/wp-abilities/v1/abilities/wpmcp/receive-site-archive/run';

    private string $endpoint;

    private string $authorization;

    /**
     * @throws \InvalidArgumentException On an unusable URL or missing credentials.
     */
    public function __construct(string $target_url, string $user, string $app_password, string $token)
    {
        $target_url = untrailingslashit(trim($target_url));
        $scheme     = (string) wp_parse_url($target_url, PHP_URL_SCHEME);
        $host       = (string) wp_parse_url($target_url, PHP_URL_HOST);

        if ('' === $host || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('target_url must be an absolute http(s) URL of the target WordPress site.');
        }
        if ('https' !== $scheme && ! apply_filters('wpmcp_migration_allow_insecure_target', false, $target_url)) {
            throw new \InvalidArgumentException('target_url must use https: the push carries a credential and a full database dump.');
        }

        if ('' !== $token) {
            $this->authorization = 'Bearer ' . $token;
        } elseif ('' !== $user && '' !== $app_password) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication is defined as base64(user:password) (RFC 7617).
            $this->authorization = 'Basic ' . base64_encode($user . ':' . $app_password);
        } else {
            throw new \InvalidArgumentException('Authenticate to the target with target_user plus target_app_password (an application password of an administrator on the target), or target_token.');
        }

        $this->endpoint = add_query_arg('rest_route', self::ROUTE, $target_url . '/');
    }

    /**
     * Run one action on the target.
     *
     * @return array<string, mixed>|\WP_Error Decoded result, or an error
     *         whose message says what the target (or the network) said.
     */
    public function call(array $input, int $timeout = 60)
    {
        $response = wp_safe_remote_post($this->endpoint, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Authorization' => $this->authorization,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'        => (string) wp_json_encode(['input' => $input]),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('wpmcp_migration_unreachable', 'Could not reach the target site: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && is_array($data)) {
            return $data;
        }

        $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : sprintf('HTTP %d with no JSON body', $code);
        $reason  = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'http_' . $code;

        if (in_array($code, [401, 403], true) && 'wpmcp_migration_receive_disabled' !== $reason) {
            $message .= ' (check that the credentials belong to an administrator on the target, that application passwords are available there, and that wpmcp is active on the target)';
        } elseif (404 === $code) {
            $message .= ' (the target has no receive-site-archive ability: is wpmcp installed and up to date there?)';
        }

        return new \WP_Error('wpmcp_migration_target_error', sprintf('The target refused the request: %s', $message), [
            'status'      => $code,
            'target_code' => $reason,
            'target_data' => is_array($data) ? ($data['data'] ?? null) : null,
        ]);
    }
}
