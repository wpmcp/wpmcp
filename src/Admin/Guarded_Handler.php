<?php

namespace WPMCP\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * An admin-post or AJAX handler behind the management check (issue #479).
 * Refuses with a 403 before the wrapped handler runs, so the handler's own
 * nonce and capability checks are never the only line. Registered through
 * Management_Access::add_handler(); the entry point test recognizes a
 * guarded handler by this class.
 *
 * The wrapped handler is called with no arguments (issue #587). Those hooks
 * carry none: admin-post.php and admin-ajax.php fire them bare, so WordPress
 * hands each callback one empty string, and forwarding it put '' into the
 * ?callable test seam several handlers take first, a TypeError that turned
 * the request log export (and the connection bundle download and the
 * banner dismissals) into a critical error page.
 */
final class Guarded_Handler
{
    /** @var callable */
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /** @param mixed ...$args What the hook passed; never forwarded (see above). */
    public function __invoke(...$args): void
    {
        unset($args);
        if (! Management_Access::can_manage()) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => Management_Access::refusal_message()], 403);
            }
            wp_die(esc_html(Management_Access::refusal_message()), '', ['response' => 403]);
        }

        ($this->handler)();
    }
}
