<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the RFC 8414 OAuth 2.0 Authorization Server Metadata document,
 * served at /.well-known/oauth-authorization-server by
 * Metadata_Endpoint::authorization_server(). $issuer is the site's own
 * origin (scheme + host, no trailing slash), matching RFC 8414's
 * requirement that 'issuer' be the authorization server's own identifier.
 *
 * Deliberately advertises only what this plugin actually implements:
 * authorization_code as the sole grant type and S256 as the sole PKCE
 * method, since this plugin never accepts the 'plain' PKCE transformation or
 * any other grant type (issue #43 scope).
 */
class Authorization_Server_Metadata
{
    public static function build(string $issuer): array
    {
        $base = rtrim($issuer, '/') . '/wp-json/wpmcp/v1/oauth';

        return [
            'issuer'                                => $issuer,
            'authorization_endpoint'                 => $base . '/authorize',
            'token_endpoint'                          => $base . '/token',
            'registration_endpoint'                   => $base . '/register',
            'response_types_supported'                => ['code'],
            'grant_types_supported'                   => ['authorization_code'],
            'code_challenge_methods_supported'        => ['S256'],
            'token_endpoint_auth_methods_supported'   => ['none', 'client_secret_post'],
        ];
    }
}
