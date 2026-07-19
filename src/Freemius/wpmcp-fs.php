<?php

/**
 * Global Freemius accessor for wpmcp.
 *
 * Deliberately NOT namespaced: the Freemius SDK convention (and its
 * free/premium build tooling) expects a global <prefix>_fs() helper.
 * Declaring this inside the namespaced Bootstrap class file would define
 * \WPMCP\Freemius\wpmcp_fs() instead, which nothing else would find.
 *
 * Loaded exclusively by \WPMCP\Freemius\Bootstrap::init() AFTER the SDK
 * itself has been required, so fs_dynamic_init() is guaranteed to exist.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('wpmcp_fs')) {
    // phpcs:ignore WordPress -- wpmcp_fs and fs_dynamic_init are Freemius SDK conventions.
    function wpmcp_fs()
    {
        global $wpmcp_fs;

        if (! isset($wpmcp_fs)) {
            $wpmcp_fs = fs_dynamic_init(\WPMCP\Freemius\Bootstrap::config());
        }

        return $wpmcp_fs;
    }
}
