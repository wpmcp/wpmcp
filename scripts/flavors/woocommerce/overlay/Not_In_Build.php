<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The answer a direct tools/call gets for a tool this build leaves out
 * (issue #1140).
 *
 * Ships only in the WordPress.org WooCommerce listing: the strip copies it in
 * from scripts/flavors/woocommerce/overlay/ and wires it in front of
 * Hidden_Tool_Refusal's and Stdio_Transport's plugin-inactive lookups, so a
 * name below gets this refusal instead of the adapter's "Tool not found".
 * It grants nothing: the tool is not in the zip at all.
 *
 * ABILITIES must match scripts/flavors/woocommerce/narrowed.php exactly;
 * assert-narrowed.php fails the build when they differ.
 */
final class Not_In_Build
{
    /** ability name => what it is, as the refusal names it */
    public const ABILITIES = [
        'wpmcp/write-file'             => 'file writes',
        'wpmcp/edit-file'              => 'file writes',
        'wpmcp/delete-file'            => 'file writes',
        'wpmcp/query'                  => 'raw SQL',
        'wpmcp/insert-row'             => 'database writes',
        'wpmcp/update-rows'            => 'database writes',
        'wpmcp/delete-rows'            => 'database writes',
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
    ];

    /**
     * The refusal for $name, an ability name or the MCP tool name a client
     * sees, or null when this build does not leave it out.
     */
    public static function refusal(string $name): ?\WP_Error
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }

        foreach (self::ABILITIES as $ability => $category) {
            if ($ability !== $name && Stdio_Transport::tool_name($ability) !== $name) {
                continue;
            }

            return new \WP_Error(
                'not_in_this_build',
                sprintf(
                    '"%s" is not in this build. WP MCP for WooCommerce ships store, content and safety tools only, and leaves out %s. The full WP MCP plugin includes it.',
                    $ability,
                    $category
                ),
                [ 'ability' => $ability, 'category' => $category ]
            );
        }

        return null;
    }
}
