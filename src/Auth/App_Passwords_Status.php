<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whether Application Passwords work on this site, and if not, why (issue
 * #883). Another plugin can switch them off (Wordfence does by default, with
 * add_filter('wp_is_application_passwords_available', '__return_false')),
 * and WordPress then ignores a valid Authorization header, so an MCP client
 * sees only a generic 401. This names the cause and the way back.
 *
 * Nothing here reads, stores or returns a credential or a header value.
 */
final class App_Passwords_Status
{
    /** Whether Application Passwords are available on this site. */
    public static function available(): bool
    {
        return ! function_exists('wp_is_application_passwords_available') || wp_is_application_passwords_available();
    }

    /**
     * The state as data: available, and when it is not, a reason code
     * (https_required, disabled_by_plugin), the plugin when known, and one
     * message with the fix.
     *
     * $detail adds the plugin's name and its setting path; only authenticated
     * admin contexts ask for it, so an anonymous caller cannot fingerprint the
     * site's security plugins.
     *
     * @return array{available: bool, reason?: string, disabled_by?: string, message?: string}
     */
    public static function state(bool $detail = false): array
    {
        if (self::available()) {
            return [ 'available' => true ];
        }

        $oauth = 'Or connect with OAuth instead (wpmcp > Connection).';

        if (function_exists('wp_is_application_passwords_supported') && ! wp_is_application_passwords_supported()) {
            return [
                'available' => false,
                'reason'    => 'https_required',
                'message'   => 'Application Passwords are unavailable because this site is not served over HTTPS. Serve it over HTTPS, or ' . lcfirst($oauth),
            ];
        }

        if (! $detail) {
            return [
                'available' => false,
                'reason'    => 'disabled',
                'message'   => 'Application Passwords are disabled on this site. The site administrator can check the Connection screen in wp-admin (wpmcp > Connection).',
            ];
        }

        // Blame Wordfence only when its own setting is what turns them off.
        if (class_exists('wfConfig', false) && (bool) \wfConfig::get('loginSec_disableApplicationPasswords')) {
            return [
                'available'   => false,
                'reason'      => 'disabled_by_plugin',
                'disabled_by' => 'Wordfence',
                'message'     => 'Application Passwords are disabled on this site by Wordfence. Turn off "Disable WordPress application passwords" under Wordfence > Firewall > All Firewall Options > Brute Force Protection. ' . $oauth,
            ];
        }

        return [
            'available' => false,
            'reason'    => 'disabled_by_plugin',
            'message'   => 'Application Passwords are disabled on this site by another plugin or a filter (wp_is_application_passwords_available returns false). Turn that setting off, or remove the filter. ' . $oauth,
        ];
    }

    /**
     * rest_post_dispatch: when an MCP request that carried a Basic
     * Authorization header is refused with 401 while Application Passwords
     * are unavailable, say so in the error. The status and error code are
     * kept; only the message and the data gain the reason.
     *
     * @param mixed $response
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function explain_unauthorized($response, $server, $request)
    {
        if (! $response instanceof \WP_REST_Response || 401 !== $response->get_status() || ! $request instanceof \WP_REST_Request) {
            return $response;
        }
        if (! Mcp_Resource::is_route((string) $request->get_route())) {
            return $response;
        }
        // Only the scheme is looked at, never the credential.
        if (! str_starts_with(strtolower(ltrim((string) $request->get_header('authorization'))), 'basic ')) {
            return $response;
        }
        $state = self::state();
        if ($state['available']) {
            return $response;
        }

        // Anonymous callers get the public wording and no plugin name.
        $extra = [ 'application_passwords_available' => false, 'reason' => 'disabled' ];

        $data = $response->get_data();
        if (! is_array($data)) {
            return $response;
        }
        if (is_array($data['error'] ?? null)) {
            $data['error']['message'] = $state['message'];
            $data['error']['data']    = $extra + (is_array($data['error']['data'] ?? null) ? $data['error']['data'] : []);
        } else {
            $data['message'] = $state['message'];
            $data['data']    = $extra + (is_array($data['data'] ?? null) ? $data['data'] : [ 'status' => 401 ]);
        }
        $response->set_data($data);

        return $response;
    }
}
