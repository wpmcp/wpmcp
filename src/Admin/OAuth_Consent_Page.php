<?php

namespace WPMCP\Admin;

use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Identity\Identity_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The OAuth consent screen (issue #454).
 *
 * An MCP client sends the user's browser to the authorization endpoint,
 * which redirects here (Endpoints::authorize_redirect()). This is an
 * admin-post.php action, so WordPress handles signing in: a signed-out
 * browser is sent to the login screen and comes back. The screen names the
 * client and where it will send the user back to, and asks for an access
 * level:
 *
 *  - Full access: the client can do what this user can do (scope `mcp`);
 *  - Read-only: it can list and read, and every other ability is refused
 *    (scope `mcp:read`). A client that asked only for `mcp:read` is offered
 *    nothing else;
 *  - a scoped identity (site owners only): the client is limited exactly as
 *    that identity is, including its allowed IPs.
 *
 * Nothing is shown and nothing redirects until Authorization_Grant::validate()
 * accepts the request, so the screen never names an unknown client and never
 * sends the browser to a redirect_uri the client did not register. The
 * decision is a nonce-checked POST; allowing issues the code through
 * Authorization_Grant::authorize(), which records the choice in
 * Client_Access, and the browser goes back with `code` and `state`. Denying
 * sends it back with `error=access_denied`. Framing is refused, so the
 * buttons cannot be clickjacked.
 */
class OAuth_Consent_Page
{
    public const ACTION       = 'wpmcp_oauth_authorize';
    public const NONCE_ACTION = 'wpmcp_oauth_consent';

    /** The authorization request parameters carried through the screen. */
    private const PARAMS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource'];

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [new self(), 'handle']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'require_login']);
    }

    /** The consent screen URL for an authorization request. */
    public static function url(array $params): string
    {
        $args = ['action' => self::ACTION] + self::pick($params);

        return add_query_arg(array_map('rawurlencode', $args), admin_url('admin-post.php'));
    }

    /** admin_post_nopriv: send a signed-out browser to log in and back. */
    public static function require_login(): void
    {
        auth_redirect();
    }

    /** admin_post: show the screen (GET) or act on the decision (POST). */
    public function handle(): void
    {
        nocache_headers();
        send_frame_options_header();

        if (! OAuth_Config::is_enabled()) {
            wp_die(esc_html__('OAuth is not enabled on this site.', 'wpmcp'), '', ['response' => 404]);
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ('POST' === $method) {
            // The nonce is verified inside decide().
            $location = $this->decide(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (is_wp_error($location)) {
                wp_die(esc_html($location->get_error_message()), esc_html__('Authorization failed', 'wpmcp'), ['response' => 400]);
            }
            // The client's own registered redirect_uri, checked by
            // Authorization_Grant::validate(); it is on another host by design.
            wp_redirect($location); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        }

        echo $this->render(wp_unslash($_GET)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value.
        exit;
    }

    /**
     * Act on a posted decision. Returns where to send the browser: the
     * client's redirect_uri with a code or access_denied. A WP_Error when
     * the nonce or the request is not valid, in which case the browser is
     * not sent anywhere.
     */
    public function decide(array $post): string|\WP_Error
    {
        if (! wp_verify_nonce(self::str($post['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            return new \WP_Error('invalid_nonce', __('Security check failed. Go back to the application and connect again.', 'wpmcp'));
        }

        $params  = self::pick($post);
        $request = Authorization_Grant::validate($params);
        if (is_wp_error($request)) {
            return $request;
        }

        $back = ['state' => self::str($post['state'] ?? '')];
        if ('allow' !== self::str($post['decision'] ?? '')) {
            return self::back($request['redirect_uri'], ['error' => 'access_denied'] + $back);
        }

        $grant = Authorization_Grant::authorize($params + ['access' => self::str($post['access'] ?? '')]);
        if (is_wp_error($grant)) {
            return $grant;
        }

        return self::back($request['redirect_uri'], ['code' => $grant['code']] + $back);
    }

    /** The screen's HTML for an authorization request. */
    public function render(array $params): string
    {
        $params  = self::pick($params);
        $request = Authorization_Grant::validate($params);
        $title   = __('Connect an application', 'wpmcp');

        if (is_wp_error($request)) {
            $body = '<h1>' . esc_html__('This connection request cannot be accepted', 'wpmcp') . '</h1>'
                . '<p>' . esc_html($request->get_error_message()) . '</p>'
                . '<p>' . esc_html__('Go back to the application and try connecting again.', 'wpmcp') . '</p>';

            return self::document($title, $body);
        }

        $user      = wp_get_current_user();
        $name      = '' !== $request['client_name'] ? $request['client_name'] : __('An application', 'wpmcp');
        $host      = (string) wp_parse_url($request['redirect_uri'], PHP_URL_HOST);
        $read_only = Client_Access::is_read_only_scope($request['scope']);

        $options = [];
        if (! $read_only) {
            $options[ Client_Access::FULL ] = [__('Full access', 'wpmcp'), __('It can do anything your account can do on this site, including changing content and settings.', 'wpmcp')];
        }
        $options[ Client_Access::READ ] = [__('Read only', 'wpmcp'), __('It can look things up and read content. Anything that would change the site is refused.', 'wpmcp')];
        if (current_user_can('manage_options')) {
            foreach (Identity_Store::list() as $identity) {
                $identity_name = (string) ($identity['name'] ?? '');
                if ('' === $identity_name) {
                    continue;
                }
                $options[ 'identity:' . $identity_name ] = [
                    /* translators: %s: scoped identity name. */
                    sprintf(__('As the "%s" identity', 'wpmcp'), $identity_name),
                    __('Limited to what this scoped identity allows, including its allowed IP addresses.', 'wpmcp'),
                ];
            }
        }

        $body  = '<h1>' . esc_html(sprintf(/* translators: %s: application name. */ __('%s wants to connect to this site', 'wpmcp'), $name)) . '</h1>';
        $body .= '<p>' . sprintf(
            /* translators: 1: user display name, 2: site name, 3: redirect host. */
            esc_html__('Signed in as %1$s on %2$s. After you decide, you will be sent back to %3$s.', 'wpmcp'),
            '<strong>' . esc_html($user->display_name) . '</strong>',
            '<strong>' . esc_html(get_bloginfo('name')) . '</strong>',
            '<code>' . esc_html($host) . '</code>'
        ) . '</p>';
        $body .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $body .= '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        $body .= wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false);
        foreach ($params as $key => $value) {
            $body .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        $body .= '<fieldset><legend>' . esc_html__('What may it do?', 'wpmcp') . '</legend>';
        $first = true;
        foreach ($options as $value => [$label, $help]) {
            $body .= '<label class="choice"><input type="radio" name="access" value="' . esc_attr((string) $value) . '"' . ($first ? ' checked' : '') . '> '
                . '<span><strong>' . esc_html($label) . '</strong><br><span class="help">' . esc_html($help) . '</span></span></label>';
            $first = false;
        }
        $body .= '</fieldset>';
        $body .= '<p class="note">' . esc_html__('Whatever you choose, it can never do more than your own account can. A site owner can change this later on the WP MCP Connection screen.', 'wpmcp') . '</p>';
        $body .= '<p class="actions"><button type="submit" name="decision" value="allow" class="primary">' . esc_html__('Allow', 'wpmcp') . '</button> '
            . '<button type="submit" name="decision" value="deny">' . esc_html__('Deny', 'wpmcp') . '</button></p>';
        $body .= '</form>';

        return self::document($title, $body);
    }

    /** @param array<string, string> $args */
    private static function back(string $redirect_uri, array $args): string
    {
        $args = array_filter($args, static fn (string $v): bool => '' !== $v);

        return add_query_arg(array_map('rawurlencode', $args), $redirect_uri);
    }

    /**
     * The request parameters the screen carries, as strings. Anything else
     * is dropped, and a non-string value is dropped rather than coerced.
     *
     * @return array<string, string>
     */
    private static function pick(array $params): array
    {
        $out = [];
        foreach (self::PARAMS as $key) {
            if (isset($params[ $key ]) && is_string($params[ $key ])) {
                $out[ $key ] = $params[ $key ];
            }
        }

        return $out;
    }

    private static function str($value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function document(string $title, string $body): string
    {
        return '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title>'
            . '<style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:24px 16px}'
            . 'main{max-width:520px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:24px}'
            . 'h1{font-size:20px;margin:0 0 12px}fieldset{border:0;padding:0;margin:16px 0}legend{font-weight:600;margin-bottom:8px}'
            . '.choice{display:flex;gap:8px;align-items:flex-start;padding:10px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:8px;cursor:pointer}'
            . '.help,.note{color:#50575e;font-size:13px}.actions{display:flex;gap:8px}'
            . 'button{font:inherit;padding:8px 16px;border-radius:3px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer}'
            . 'button.primary{background:#2271b1;color:#fff}</style></head><body><main>'
            . $body
            . '</main></body></html>';
    }
}
