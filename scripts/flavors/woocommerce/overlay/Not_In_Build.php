<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The answer a direct tools/call gets for a tool, or an action of a kept
 * tool, that this build leaves out (issue #1140).
 *
 * Ships only in the WordPress.org WooCommerce listing: the strip copies it in
 * from scripts/flavors/woocommerce/overlay/ and wires it into both
 * transports ahead of everything else, so a name below gets this refusal
 * instead of the adapter's "Tool not found", and a stripped action gets it
 * instead of the input schema's "is not one of". It grants nothing: neither
 * the tools nor the actions are in the zip at all.
 *
 * ABILITIES and ACTIONS must match scripts/flavors/woocommerce/narrowed.php
 * exactly; assert-narrowed.php fails the build when they differ.
 */
final class Not_In_Build
{
    /** ability name => what it is, as the refusal names it */
    public const ABILITIES = [
        'wpmcp/write-file'             => 'file writes',
        'wpmcp/edit-file'              => 'file writes',
        'wpmcp/delete-file'            => 'file writes',
        'wpmcp/read-file'              => 'file access',
        'wpmcp/list-directory'         => 'file access',
        'wpmcp/search-files'           => 'file access',
        'wpmcp/query'                  => 'raw SQL',
        'wpmcp/insert-row'             => 'database writes',
        'wpmcp/update-rows'            => 'database writes',
        'wpmcp/delete-rows'            => 'database writes',
        'wpmcp/rewrite-site-urls'      => 'site-wide search and replace',
        'wpmcp/find-replace-content'   => 'site-wide search and replace',
        'wpmcp/create-php-snippet'     => 'the PHP snippet store',
        'wpmcp/list-php-snippets'      => 'the PHP snippet store',
        'wpmcp/get-php-snippet'        => 'the PHP snippet store',
        'wpmcp/update-php-snippet'     => 'the PHP snippet store',
        'wpmcp/delete-php-snippet'     => 'the PHP snippet store',
        'wpmcp/deactivate-php-snippet' => 'the PHP snippet store',
        'wpmcp/activate-php-snippet'   => 'the PHP snippet store',
        'wpmcp/validate-php-snippet'   => 'the PHP snippet store',
        'wpmcp/run-php-snippet'        => 'the PHP snippet store',
        'wpmcp/create-code-snippet'    => 'code snippet creation',
        'wpmcp/push-site-archive'      => 'site migration',
        'wpmcp/receive-site-archive'   => 'site migration',
        'wpmcp/restore-site-backup'    => 'whole-site restore from a backup archive',
    ];

    /** ability name => [action => what it is] for actions a kept tool loses */
    public const ACTIONS = [
        'wpmcp/incident-response' => [
            'rotate-salts'             => 'salt rotation (it rewrites wp-config.php)',
            'reinstall-core-files'     => 'file changes',
            'quarantine-file'          => 'file changes',
            'list-quarantine'          => 'file changes',
            'restore-quarantined-file' => 'file changes',
            'purge-quarantined-file'   => 'file changes',
        ],
    ];

    /**
     * The refusal for a tools/call of $name, an ability name or the MCP tool
     * name a client sees, with $arguments, or null when this build does not
     * leave that call out.
     *
     * @param array<string, mixed> $arguments
     */
    public static function refusal(string $name, array $arguments = []): ?\WP_Error
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        foreach (self::ABILITIES as $ability => $category) {
            if (self::names($ability, $name)) {
                return new \WP_Error(
                    'not_in_this_build',
                    sprintf(
                        '"%s" is not in this build. Safe MCP for WooCommerce ships store, content and safety tools only, and leaves out %s. The full WP MCP plugin includes it.',
                        $ability,
                        $category
                    ),
                    [ 'ability' => $ability, 'category' => $category ]
                );
            }
        }

        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';
        foreach (self::ACTIONS as $ability => $actions) {
            if ('' === $action || ! isset($actions[ $action ]) || ! self::names($ability, $name)) {
                continue;
            }

            return new \WP_Error(
                'not_in_this_build',
                sprintf(
                    'The "%s" action of "%s" is not in this build. Safe MCP for WooCommerce ships store, content and safety tools only, and leaves out %s. The full WP MCP plugin includes it.',
                    $action,
                    $ability,
                    $actions[ $action ]
                ),
                [ 'ability' => $ability, 'action' => $action, 'category' => $actions[ $action ] ]
            );
        }

        return null;
    }

    /**
     * The same refusal for one JSON-RPC request, or null when it is not a
     * tools/call this build leaves out.
     *
     * @param array<string, mixed> $message
     */
    public static function for_message(array $message): ?\WP_Error
    {
        if ('tools/call' !== ($message['method'] ?? null)) {
            return null;
        }
        $params    = is_array($message['params'] ?? null) ? $message['params'] : [];
        $name      = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return self::refusal($name, $arguments);
    }

    private static function names(string $ability, string $name): bool
    {
        return $ability === $name || Stdio_Transport::tool_name($ability) === $name;
    }
}
