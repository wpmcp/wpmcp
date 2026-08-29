<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class System_Prompt
{
    /**
     * Builds the server-authored system prompt with sanitized WordPress site context and safety rules.
     */
    public static function build(int $user_id): string
    {
        $user = get_userdata($user_id);
        $user_login = $user ? $user->user_login : 'unknown_admin';
        $site_url = function_exists('get_site_url') ? get_site_url() : 'http://localhost';
        $raw_site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name', 'display') : 'WordPress Site';

        // Sanitize site name against prompt injection: strip control characters, newlines, and truncate
        $clean_site_name = preg_replace('/[\x00-\x1F\x7F\r\n]/u', ' ', $raw_site_name);
        $clean_site_name = trim((string) preg_replace('/\s+/', ' ', (string) $clean_site_name));
        if (function_exists('mb_substr')) {
            $clean_site_name = mb_substr($clean_site_name, 0, 100);
        } else {
            $clean_site_name = substr($clean_site_name, 0, 100);
        }

        if ('' === $clean_site_name) {
            $clean_site_name = 'WordPress Site';
        }

        $clean_site_url = filter_var($site_url, FILTER_SANITIZE_URL);
        if (false === $clean_site_url || '' === $clean_site_url) {
            $clean_site_url = 'http://localhost';
        }

        return implode("\n\n", [
            "You are the AI Admin Assistant for this WordPress installation.",
            "<site_context>\n" .
            "Site Name: {$clean_site_name}\n" .
            "Site URL: {$clean_site_url}\n" .
            "Active User Identity: `chat:{$user_login}`\n" .
            "</site_context>",
            "You have access to tools for inspecting and managing this WordPress site. Every mutating tool call passes through WPMCP's Safe_Mutation engine with automatic before-image snapshots and one-click rollback.",
            "CRITICAL GOVERNANCE INVARIANTS:",
            "1. Read-only operations execute directly.",
            "2. Destructive and mutating actions (deleting posts, updating options, bulk alterations) require a server-issued one-time approval token from the human administrator.",
            "3. Never attempt to execute destructive mutations without presenting clear intention to the user and obtaining token authorization.",
            "4. Maintain precision and adhere to standard WordPress conventions.",
        ]);
    }
}
