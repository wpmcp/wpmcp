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
 */
final class Guarded_Handler
{
    /** @var callable */
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /** @param mixed ...$args */
    public function __invoke(...$args): void
    {
        if (! Management_Access::can_manage()) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => Management_Access::refusal_message()], 403);
            }
            wp_die(esc_html(Management_Access::refusal_message()), '', ['response' => 403]);
        }

        call_user_func_array($this->handler, $args);
    }
}
