<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The refusal a default-off tool or operation gives while its opt-in is
 * closed (issues #631 and the opt_in_required unification).
 *
 * Two error codes describe a switched-off feature, and they mean different
 * things:
 *
 *  - opt_in_required: the feature ships off and the site has not opted in.
 *    Every such refusal, from a tool, a pack operation, a woo-write op or a
 *    gate such as the ability bridge, uses this code and a message built
 *    here: what is off, that it is off on purpose, and the exact filter (and
 *    constant, where one exists) a developer sets to switch it on.
 *  - operation_disabled: the feature is on by default and this site has
 *    switched it off (a wpmcp_integration_op_enabled or wpmcp_woo_op_enabled
 *    callback returned false). Opting in is not the fix, so it keeps its own
 *    code.
 *
 * As an exception it is still a RuntimeException, so every caller that
 * caught the old refusal behaves exactly as before; Registrar gives it the
 * opt_in_required code. Code that returns a structured error rather than
 * throwing uses message() and error() so the wording stays the same.
 */
class Opt_In_Required extends \RuntimeException
{
    /** The error code every closed opt-in answers with. */
    public const CODE = 'opt_in_required';

    /**
     * @param string $disabled The opening sentence, such as "The delete-plugin tool is disabled."
     * @param string $filter   The opt-in filter, such as wpmcp_enable_delete_plugin.
     * @param string $constant The wp-config.php constant that also opens the gate, if any.
     * @param string $warning  A sentence on why the gate is off, appended when given.
     */
    public function __construct(string $disabled, string $filter, string $constant = '', string $warning = '')
    {
        parent::__construct(self::message($disabled, $filter, $constant, $warning));
    }

    /**
     * The refusal message: $disabled, then how to switch it on.
     *
     * @param string $snippet The PHP that opens the gate, when it is not
     *                        add_filter('$filter', '__return_true'); (a
     *                        per-operation filter takes a callback).
     */
    public static function message(string $disabled, string $filter, string $constant = '', string $warning = '', string $snippet = ''): string
    {
        $snippet = '' === $snippet ? sprintf("add_filter('%s', '__return_true');", $filter) : $snippet;
        $how     = '' === $constant
            ? sprintf('%s in a plugin or mu-plugin', $snippet)
            : sprintf("define('%s', true); in wp-config.php, or %s in a plugin or mu-plugin", $constant, $snippet);

        return trim(
            sprintf(
                '%s It is off by default and has no wp-admin setting; a site developer can switch it on with %s. %s',
                $disabled,
                $how,
                $warning
            )
        );
    }

    /**
     * The same refusal as a WP_Error, for tools that return errors rather
     * than throw. Its data names the filter (and constant) so a client can
     * show it without parsing the message.
     *
     * @param array<string, mixed> $data Extra data merged in (such as a status).
     */
    public static function error(string $disabled, string $filter, string $constant = '', string $warning = '', array $data = []): \WP_Error
    {
        $data += [ 'filter' => $filter ];
        if ('' !== $constant) {
            $data += [ 'constant' => $constant ];
        }

        return new \WP_Error(self::CODE, self::message($disabled, $filter, $constant, $warning), $data);
    }
}
