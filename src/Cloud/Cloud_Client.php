<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HTTP client for the WP MCP Cloud REST contract (/wpmcp-cloud/v1), the single
 * seam between the plugin and the cloud backend.
 *
 * Contract (v1), Bearer-authenticated with the site's API key:
 *   GET  /me                → { account: { id, email, plan } }
 *   GET  /assets            → { assets: [ { id, type, name, title, spec } ] }
 *   POST /assets { type, name, title, spec } → { asset: { id, ... } }
 *
 * Keeping this the ONLY place that knows the wire format means the backend can
 * be swapped (WordPress → a scalable service) without changing any tool.
 */
class Cloud_Client
{
    private const API_BASE = '/wpmcp-cloud/v1';

    /** @return array|\WP_Error decoded JSON body, or an error. */
    public function get(string $path)
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array $extra_args Per-request wp_remote_request() overrides.
     *                          The gateway credential upload (issue #130)
     *                          uses this to send its one long-lived secret
     *                          with redirection disabled and TLS verified,
     *                          because the Requests library re-sends a POST
     *                          body on a 30x and an http Location would
     *                          replay that secret in cleartext.
     * @return array|\WP_Error
     */
    public function post(string $path, array $body, array $extra_args = [])
    {
        return $this->request('POST', $path, $body, $extra_args);
    }

    /** @return array|\WP_Error */
    private function request(string $method, string $path, ?array $body = null, array $extra_args = [])
    {
        if (! Cloud_Config::is_configured()) {
            return new \WP_Error('cloud_not_configured', 'Connect to WP MCP Cloud first with cloud-connect (URL + API key).');
        }

        $url  = Cloud_Config::base_url() . self::API_BASE . $path;
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . Cloud_Config::api_key(),
                'Accept'        => 'application/json',
            ],
        ];
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = (string) wp_json_encode($body);
        }

        $response = wp_remote_request($url, array_merge($args, $extra_args));
        if (is_wp_error($response)) {
            return new \WP_Error('cloud_unreachable', 'Could not reach WP MCP Cloud: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : "HTTP {$code}";
            return new \WP_Error('cloud_error', 'WP MCP Cloud returned an error: ' . $message, ['status' => $code]);
        }

        return is_array($data) ? $data : [];
    }
}
