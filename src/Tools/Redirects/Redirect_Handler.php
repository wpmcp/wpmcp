<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end enforcement of managed redirects (issue #128).
 *
 * Hooked to template_redirect at priority 1, deliberately AHEAD of core's
 * redirect_canonical (priority 10). Precedence matters and is not an
 * accident: for a path whose post was renamed or removed, redirect_canonical
 * will either guess a "close enough" post or let the request fall through to
 * a 404. An explicitly managed redirect is a stated intention about where
 * that URL should go, so it must win over core's guess. Once we redirect, the
 * request ends, so redirect_canonical never runs for that URL; when no
 * managed redirect matches we do nothing at all and core's canonical
 * behavior is completely untouched.
 *
 * The DECISION (resolve) is kept separate from the SIDE EFFECT (maybe_redirect,
 * which sends the Location header and terminates the request), matching
 * Maintenance_Guard: the interesting logic is then unit testable without
 * killing the test process, and the redirector itself is constructor-injected
 * so the wiring can be exercised too.
 */
class Redirect_Handler
{
    /** Priority on template_redirect. Must stay below redirect_canonical's 10. */
    public const PRIORITY = 1;

    /** @var callable(string,int):void */
    private $redirector;

    public function __construct(?callable $redirector = null)
    {
        $this->redirector = $redirector ?? static function (string $target, int $code): void {
            wp_redirect($target, $code); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        };
    }

    /**
     * Request contexts a managed redirect must never fire in: wp-admin, the
     * REST API (which the MCP transport itself rides on), cron, and the login
     * screen. Redirecting any of those would be able to lock an administrator
     * out of the site with a single bad row.
     */
    public function should_skip(string $request_uri): bool
    {
        if (is_admin()) {
            return true;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $path = ltrim(Redirect_Store::normalize_path($path), '/');

        return (bool) preg_match('#^(wp-admin|wp-json|wp-login\.php|wp-cron\.php)(/|$)#', $path);
    }

    /**
     * Decide what (if anything) the given request URI should redirect to.
     *
     * @return array{redirect_id:int,target:string,status_code:int}|null
     */
    public function resolve(string $request_uri): ?array
    {
        if ($this->should_skip($request_uri)) {
            return null;
        }

        $source = Redirect_Store::normalize_path($request_uri);
        if ('/' === $source) {
            return null; // Never redirect the site root out from under itself.
        }

        $row = Redirect_Store::find_by_source_cached($source, true);
        if (! $row || ! $row['enabled']) {
            return null;
        }

        $target = Redirect_Store::resolve_target($row);
        if ('' === $target) {
            return null; // Target post is gone: treat the row as inactive, do not 404-loop visitors.
        }

        // A row that somehow points at itself (e.g. its target post was given
        // the source's own slug after the row was written) is ignored rather
        // than served, so a stale row can never become an infinite loop.
        if (Redirect_Store::is_internal($target) && Redirect_Store::normalize_path($target) === $source) {
            return null;
        }

        // Forward the original query string to a target that does not carry
        // one of its own, so campaign/tracking parameters survive the hop.
        $query = (string) wp_parse_url($request_uri, PHP_URL_QUERY);
        if ('' !== $query && false === strpos($target, '?')) {
            $target .= '?' . $query;
        }

        return [
            'redirect_id' => $row['id'],
            'target'      => $target,
            'status_code' => Redirect_Store::clamp_status_code($row['status_code']),
        ];
    }

    /** Hooked to template_redirect. Records the hit, then redirects and exits. */
    public function maybe_redirect(): void
    {
        $uri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : '';

        $decision = $this->resolve($uri);
        if (null === $decision) {
            return;
        }

        Redirect_Store::record_hit($decision['redirect_id']);
        ($this->redirector)($decision['target'], $decision['status_code']);
    }
}
