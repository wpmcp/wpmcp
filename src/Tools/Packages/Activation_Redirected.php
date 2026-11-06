<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrown from the wp_redirect filter while Activation_Guard runs an
 * activation, so a plugin's wp_redirect() (or wp_safe_redirect()) and the
 * exit that follows it never end the MCP request. Caught by the guard; it
 * never reaches a client.
 */
final class Activation_Redirected extends \RuntimeException
{
}
