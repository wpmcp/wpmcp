<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the RFC 9728 OAuth 2.0 Protected Resource Metadata document, served
 * at /.well-known/oauth-protected-resource by
 * Metadata_Endpoint::protected_resource(). This is the document the MCP
 * authorization spec references so a client can discover which
 * authorization server protects a given resource. The resource is the MCP
 * endpoint URL (Mcp_Resource::canonical()), the exact audience every token
 * is bound to; the authorization server is the site's own origin, since
 * this plugin is both in one.
 */
class Protected_Resource_Metadata
{
    /**
     * @param string      $resource             The protected resource: the MCP endpoint URL.
     * @param string|null $authorization_server The issuer; defaults to $resource.
     */
    public static function build(string $resource, ?string $authorization_server = null): array
    {
        return [
            'resource'                 => $resource,
            'authorization_servers'    => [$authorization_server ?? $resource],
            'scopes_supported'         => Mcp_Resource::SCOPES_SUPPORTED,
            'bearer_methods_supported' => ['header'],
        ];
    }
}
