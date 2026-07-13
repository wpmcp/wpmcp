<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Authorization_Server_Metadata;

/**
 * Authorization_Server_Metadata builds the RFC 8414 OAuth 2.0 Authorization
 * Server Metadata document served at /.well-known/oauth-authorization-server.
 * Every field asserted here is a field the RFC (or the MCP auth spec that
 * references it) requires a client to be able to discover: the issuer, the
 * three endpoint URLs, and the fact that only the authorization_code grant
 * with S256 PKCE is supported (this plugin never accepts 'plain').
 */
class AuthorizationServerMetadataTest extends \WP_UnitTestCase
{
    public function test_document_contains_the_issuer(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame('https://example.com', $doc['issuer']);
    }

    public function test_document_contains_the_three_endpoint_urls(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame('https://example.com/wp-json/wpmcp/v1/oauth/authorize', $doc['authorization_endpoint']);
        $this->assertSame('https://example.com/wp-json/wpmcp/v1/oauth/token', $doc['token_endpoint']);
        $this->assertSame('https://example.com/wp-json/wpmcp/v1/oauth/register', $doc['registration_endpoint']);
    }

    public function test_only_authorization_code_grant_is_advertised(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame(['authorization_code'], $doc['grant_types_supported']);
    }

    public function test_only_s256_pkce_method_is_advertised(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame(['S256'], $doc['code_challenge_methods_supported']);
    }

    public function test_response_types_supported_is_code_only(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertSame(['code'], $doc['response_types_supported']);
    }

    public function test_token_endpoint_auth_methods_include_none_and_post(): void
    {
        // 'none' supports public clients (native/CLI using PKCE alone,
        // OAuth 2.1's recommended posture); client_secret_post supports
        // confidential clients that were issued a secret at DCR time.
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertContains('none', $doc['token_endpoint_auth_methods_supported']);
        $this->assertContains('client_secret_post', $doc['token_endpoint_auth_methods_supported']);
    }
}
