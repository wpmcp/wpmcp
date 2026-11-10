<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs activate_plugin() for the MCP tools so the reply is always JSON
 * (issue #728, reopened).
 *
 * WordPress activates a plugin by loading its main file inside the request
 * that asked for it, and some plugins end that request while they load or
 * activate: they redirect to a welcome page, or a bundled SDK that finds an
 * older copy of itself already loaded sends a Location header back to the
 * current URL and exits, so the page reloads with the newer copy. In wp-admin
 * that is a page reload. On the MCP endpoint it was a bare 302 to the
 * endpoint with no body, no reply and no request log, and the plugin stayed
 * inactive.
 *
 * Two layers:
 *
 * - wp_redirect() and wp_safe_redirect() are stopped before they send a
 *   header: the wp_redirect filter throws, which unwinds out of the plugin's
 *   code before its exit runs. The activation then stands or fails on
 *   whether the plugin reached the active list, which the tools' verify step
 *   checks as before.
 * - A raw header('Location: ...') followed by exit cannot be stopped from
 *   PHP. For that, a shutdown handler notices the request ended while an
 *   activation was still running, drops the Location header and the 302,
 *   and answers the JSON-RPC request with a tool error that says what state
 *   the plugin is in. Safe_Mutation's own shutdown check has already voided
 *   the undo point when nothing changed.
 *
 * Activations are serialised site wide by Plugin_State::locked(), whichever
 * plugin they name.
 */
final class Activation_Guard
{
    /** The plugin whose activation is running, or null. */
    private static ?string $plugin = null;

    private static bool $network_wide = false;

    private static bool $shutdown_registered = false;

    /**
     * activate_plugin() with redirects trapped.
     *
     * @param bool $redirected Set to true when the plugin tried to redirect.
     * @return mixed activate_plugin()'s result; null when a redirect was
     *               stopped, so the caller's verify step decides from the
     *               stored active list whether the activation landed.
     */
    public static function activate(string $plugin, bool $network_wide, ?bool &$redirected = null)
    {
        $redirected = false;
        $level      = ob_get_level();
        $trap       = static function ($location): void {
            throw new Activation_Redirected(esc_html(is_string($location) ? $location : ''));
        };

        if (! self::$shutdown_registered) {
            register_shutdown_function([ self::class, 'on_shutdown' ]);
            self::$shutdown_registered = true;
        }

        self::$plugin       = $plugin;
        self::$network_wide = $network_wide;
        add_filter('wp_redirect', $trap, PHP_INT_MAX);
        try {
            return activate_plugin($plugin, '', $network_wide);
        } catch (Activation_Redirected $e) {
            $redirected = true;
            // Core's activation sandbox buffer (and any the plugin opened)
            // was skipped by the throw.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            return null;
        } finally {
            remove_filter('wp_redirect', $trap, PHP_INT_MAX);
            self::$plugin = null;
        }
    }

    public static function in_progress(): bool
    {
        return null !== self::$plugin;
    }

    /**
     * Shutdown handler: the request ended inside an activation. Replaces
     * the redirect with a JSON-RPC reply on the REST route.
     */
    public static function on_shutdown(): void
    {
        if (null === self::$plugin) {
            return;
        }
        $plugin       = self::$plugin;
        self::$plugin = null;

        if (! (defined('REST_REQUEST') && REST_REQUEST) || headers_sent()) {
            return;
        }

        $body = self::interrupted_response($plugin, Plugin_State::is_active($plugin, self::$network_wide), (string) file_get_contents('php://input'));
        if (null === $body) {
            return;
        }

        // Whatever the plugin printed before it exited is not part of the reply.
        while (ob_get_level() > 0) {
            if (! ob_end_clean()) {
                break;
            }
        }
        header_remove('Location');
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode().
    }

    /**
     * The JSON-RPC reply for a request that ended while $plugin was being
     * activated, or null when the body is not a request with an id.
     */
    public static function interrupted_response(string $plugin, bool $active, string $raw): ?string
    {
        $message = json_decode($raw, true);
        if (! is_array($message) || ! array_key_exists('id', $message) || null === $message['id']) {
            return null;
        }

        $text = $active
            ? sprintf('Plugin "%s" is active, but it ended the request while it was being activated (it redirected), so the activation was not confirmed. Check it with list-plugins; list-operations has the undo point.', $plugin)
            : sprintf('Plugin "%s" was not activated, and nothing was recorded: it ended the request with a redirect while WordPress was loading it. Some plugins do this once, the first time they load; run this call again.', $plugin);

        $json = wp_json_encode([
            'jsonrpc' => '2.0',
            'id'      => $message['id'],
            'result'  => [
                'content' => [ [ 'type' => 'text', 'text' => $text ] ],
                'isError' => true,
            ],
        ]);

        return is_string($json) ? $json : null;
    }

    /**
     * A WordPress error message as plain text for the MCP client (issue
     * #746): links are dropped with their text (they point at wp-admin,
     * often at an internal address), tags are stripped and entities decoded.
     */
    public static function plain_message(string $html): string
    {
        $text = (string) preg_replace('#\s*<a\b[^>]*>.*?</a>\s*\.?#is', ' ', $html);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The message for an activation the plugin cut short with a redirect. */
    public static function redirect_message(): string
    {
        return 'the plugin tried to redirect the request while it was being activated, before WordPress added it to the active plugins.';
    }
}
