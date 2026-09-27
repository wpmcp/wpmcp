<?php

namespace WPMCP\Tools\Security;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Configuration / hardening audit.
 *
 * Every evaluate_*() is pure. run() gathers live config values and performs ONE
 * same-host loopback GET (via wp_safe_remote_get()) to read security headers
 * and the generator meta tag. Read-only.
 */
class Hardening_Audit
{
    private const FETCH_TIMEOUT = 8;

    public function evaluate_file_edit(bool $disallowed): array
    {
        return $disallowed
            ? Security_Finding::make('harden_file_edit', 'hardening', 'File editor', 'pass', true, 'The built-in theme/plugin file editor is disabled.')
            : Security_Finding::make(
                'harden_file_edit',
                'hardening',
                'File editor',
                'warning',
                false,
                'The theme/plugin file editor is enabled.',
                'Add define( "DISALLOW_FILE_EDIT", true ); to wp-config.php so a compromised admin account cannot edit PHP from the dashboard.'
            );
    }

    public function evaluate_debug_display(bool $on, string $environment): array
    {
        if (! $on) {
            return Security_Finding::make('harden_debug_display', 'hardening', 'Debug output', 'pass', false, 'WP_DEBUG_DISPLAY is off.');
        }
        if ('production' === $environment) {
            return Security_Finding::make(
                'harden_debug_display',
                'hardening',
                'Debug output',
                'warning',
                true,
                'Debug output is shown to visitors in production.',
                'Set WP_DEBUG_DISPLAY to false in production; on-screen errors leak paths and internals.'
            );
        }
        return Security_Finding::make('harden_debug_display', 'hardening', 'Debug output', 'info', true, sprintf('Debug output is on (environment: %s).', $environment));
    }

    public function evaluate_admin_user(bool $exists): array
    {
        return $exists
            ? Security_Finding::make(
                'harden_admin_user',
                'hardening',
                'Default admin username',
                'warning',
                true,
                'A user named "admin" exists.',
                'Create a new administrator with a unique username and remove or demote the "admin" account; it is the top brute-force target.'
            )
            : Security_Finding::make('harden_admin_user', 'hardening', 'Default admin username', 'pass', false, 'No user named "admin".');
    }

    public function evaluate_xmlrpc(bool $enabled): array
    {
        return $enabled
            ? Security_Finding::make(
                'harden_xmlrpc',
                'hardening',
                'XML-RPC',
                'warning',
                true,
                'XML-RPC is enabled.',
                'If you do not use the Jetpack or app XML-RPC API, disable it (a security plugin or server rule) to remove a brute-force and pingback amplification vector.'
            )
            : Security_Finding::make('harden_xmlrpc', 'hardening', 'XML-RPC', 'pass', false, 'XML-RPC is disabled.');
    }

    public function evaluate_version_disclosure(bool $readme_present, bool $generator_meta): array
    {
        if ($readme_present || $generator_meta) {
            return Security_Finding::make(
                'harden_version_disclosure',
                'hardening',
                'Version disclosure',
                'warning',
                ['readme' => $readme_present, 'generator' => $generator_meta],
                'The WordPress version is discoverable (readme.html and/or the generator meta tag).',
                'Delete readme.html after upgrades and remove the generator meta tag so attackers cannot fingerprint your version.'
            );
        }
        return Security_Finding::make('harden_version_disclosure', 'hardening', 'Version disclosure', 'pass', false, 'No obvious WordPress version disclosure detected.');
    }

    public function evaluate_https(string $home_url): array
    {
        $scheme = strtolower((string) wp_parse_url($home_url, PHP_URL_SCHEME));
        return 'https' === $scheme
            ? Security_Finding::make('harden_https', 'hardening', 'HTTPS', 'pass', $home_url, 'The site URL uses HTTPS.')
            : Security_Finding::make(
                'harden_https',
                'hardening',
                'HTTPS',
                'warning',
                $home_url,
                'The site is not served over HTTPS.',
                'Install a TLS certificate and move the Site Address to https://, plain HTTP exposes logins and cookies.'
            );
    }

    /**
     * @param array<string,string> $headers Lower-cased response header map.
     */
    public function evaluate_security_headers(array $headers): array
    {
        $wanted  = ['x-frame-options', 'x-content-type-options', 'strict-transport-security', 'content-security-policy'];
        $missing = [];
        foreach ($wanted as $header) {
            if (empty($headers[ $header ])) {
                $missing[] = $header;
            }
        }
        if (empty($missing)) {
            return Security_Finding::make('harden_security_headers', 'hardening', 'Security headers', 'pass', [], 'All checked security headers are present.');
        }
        return Security_Finding::make(
            'harden_security_headers',
            'hardening',
            'Security headers',
            'warning',
            $missing,
            sprintf('Missing security headers: %s.', implode(', ', $missing)),
            'Add the missing headers (X-Frame-Options, X-Content-Type-Options, Strict-Transport-Security, Content-Security-Policy) at the server or via a security plugin.'
        );
    }

    /**
     * Live gather plus one same-host loopback GET.
     *
     * @return array { findings: Finding[], headers_fetch: array{ ok: bool, error: ?string } }
     */
    public function run(): array
    {
        $findings = [];

        $disallow_edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $findings[]    = $this->evaluate_file_edit($disallow_edit);

        $debug_display = defined('WP_DEBUG_DISPLAY') ? (bool) WP_DEBUG_DISPLAY : (defined('WP_DEBUG') && WP_DEBUG);
        $environment   = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $findings[]    = $this->evaluate_debug_display($debug_display, $environment);

        $admin_exists = function_exists('username_exists') && username_exists('admin');
        $findings[]   = $this->evaluate_admin_user((bool) $admin_exists);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading core's xmlrpc_enabled filter, not defining one.
        $xmlrpc     = (bool) apply_filters('xmlrpc_enabled', true) && file_exists(ABSPATH . 'xmlrpc.php');
        $findings[] = $this->evaluate_xmlrpc($xmlrpc);

        $findings[] = $this->evaluate_https(home_url());

        $fetch      = $this->fetch_home();
        $findings[] = $this->evaluate_security_headers($fetch['headers']);
        $findings[] = $this->evaluate_version_disclosure(file_exists(ABSPATH . 'readme.html'), $fetch['generator']);

        return [
            'findings'      => $findings,
            'headers_fetch' => ['ok' => $fetch['ok'], 'error' => $fetch['error']],
        ];
    }

    /** @return array{ ok: bool, headers: array<string,string>, generator: bool, error: ?string } */
    private function fetch_home(): array
    {
        $response = wp_safe_remote_get(home_url('/'), [
            'timeout'     => self::FETCH_TIMEOUT,
            'redirection' => 2,
            'user-agent'  => 'WPMCP-Security-Scanner/1.0',
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'headers' => [], 'generator' => false, 'error' => $response->get_error_message()];
        }
        $headers   = $this->normalize_headers(wp_remote_retrieve_headers($response));
        $body      = (string) wp_remote_retrieve_body($response);
        $generator = (bool) preg_match('/<meta[^>]+name=["\']generator["\'][^>]+wordpress/i', $body);
        return ['ok' => true, 'headers' => $headers, 'generator' => $generator, 'error' => null];
    }

    /**
     * @param mixed $headers
     * @return array<string,string>
     */
    private function normalize_headers($headers): array
    {
        $out = [];
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (! is_array($headers)) {
            return $out;
        }
        foreach ($headers as $key => $value) {
            $out[ strtolower((string) $key) ] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return $out;
    }
}
