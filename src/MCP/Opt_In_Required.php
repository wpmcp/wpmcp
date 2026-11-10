<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The refusal a default-off tool throws while its opt-in is closed
 * (issue #631).
 *
 * It is still a RuntimeException, so every caller that caught the old
 * refusal behaves exactly as before. The type lets Registrar give it the
 * opt_in_required code, and the constructor words every such refusal the
 * same way: what is off, that it is off on purpose, and how to switch it
 * on. None of these gates has a wp-admin setting yet, so the message says
 * so and names the filter (and the constant, where one exists) a developer
 * sets instead.
 */
class Opt_In_Required extends \RuntimeException
{
    /**
     * @param string $disabled The opening sentence, such as "The delete-plugin tool is disabled."
     * @param string $filter   The opt-in filter, such as wpmcp_enable_delete_plugin.
     * @param string $constant The wp-config.php constant that also opens the gate, if any.
     * @param string $warning  A sentence on why the gate is off, appended when given.
     */
    public function __construct(string $disabled, string $filter, string $constant = '', string $warning = '')
    {
        $how = '' === $constant
            ? sprintf("add_filter('%s', '__return_true'); in a plugin or mu-plugin", $filter)
            : sprintf("define('%s', true); in wp-config.php, or add_filter('%s', '__return_true'); in a plugin or mu-plugin", $constant, $filter);

        parent::__construct(
            trim(
                sprintf(
                    '%s It is off by default and has no wp-admin setting; a site developer can switch it on with %s. %s',
                    $disabled,
                    $how,
                    $warning
                )
            )
        );
    }
}
