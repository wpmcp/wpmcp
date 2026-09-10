<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Client_Store::create() refused because the registered-client cap
 * (MAX_CLIENTS, filterable via wpmcp_oauth_max_clients) is full.
 *
 * A subclass of \RuntimeException rather than a new exception hierarchy so
 * every existing `catch (\RuntimeException)` around registration keeps
 * working unchanged. It exists so callers can tell the ONE ordinary
 * operational failure (the store is full; free a slot) apart from a broken
 * store invariant, which needs a different message and a different action
 * from whoever reads it (issue #142).
 */
class Client_Cap_Reached extends \RuntimeException
{
}
