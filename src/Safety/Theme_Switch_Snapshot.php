<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The link between the two undo points of one theme switch (issue #869).
 *
 * switch-theme changes the 'template' and 'stylesheet' options together and
 * records one undo point for each, so the operation_ids it returns stay as
 * they were. Each undo point carries the operation id of the other. Undoing
 * either one puts BOTH options back, so a child theme and its parent never
 * come apart; undoing one alone would leave a stylesheet whose parent is not
 * the active template, which fatals every request, the MCP endpoint
 * included.
 *
 * Whatever the link says, no undo of either option may leave the pair
 * broken: refusal() checks the pair the undo would produce, and the undo is
 * refused with the reason when the stylesheet or template theme is missing
 * or the stylesheet's parent is not the template.
 */
class Theme_Switch_Snapshot
{
    public const OPTIONS = [ 'template', 'stylesheet' ];

    /** Whether this snapshot is a before-image of the template or stylesheet option. */
    public static function is_theme_option(array $snapshot): bool
    {
        return 'option' === ($snapshot['object_type'] ?? '')
            && in_array($snapshot['data']['name'] ?? '', self::OPTIONS, true);
    }

    /** The operation id of the other half of the switch, or '' when this undo point has none. */
    public static function partner_id(array $snapshot): string
    {
        return self::is_theme_option($snapshot) ? (string) ( $snapshot['data']['theme_switch_partner'] ?? '' ) : '';
    }

    /**
     * The refusal for a rollback-session over these ledger rows (newest
     * first), or null. A session ends on its OLDEST before-image of each
     * option, so the pair is checked as that final state.
     *
     * @param array[] $rows Ledger rows, newest first.
     */
    public static function session_refusal(array $rows): ?string
    {
        $final = [];
        foreach ($rows as $row) {
            $snapshot = isset($row['snapshot']) ? (array) $row['snapshot'] : (array) Snapshot::unserialize((string) ( $row['before_blob'] ?? '' ));
            if (self::is_theme_option($snapshot)) {
                $final[ (string) $snapshot['data']['name'] ] = $snapshot;
            }
        }
        return [] === $final ? null : self::refusal(array_values($final));
    }

    /**
     * Why putting these before-images back would leave the site with a broken
     * template and stylesheet pair, or null when the pair would be sound.
     *
     * @param array[] $snapshots The option snapshots about to be restored (one or both halves).
     */
    public static function refusal(array $snapshots): ?string
    {
        $target = [
            'template'   => (string) get_option('template'),
            'stylesheet' => (string) get_option('stylesheet'),
        ];
        foreach ($snapshots as $snapshot) {
            if (! self::is_theme_option($snapshot)) {
                continue;
            }
            $name = (string) $snapshot['data']['name'];
            if (empty($snapshot['data']['existed'])) {
                return sprintf('it would remove the "%s" option, which leaves WordPress without an active theme.', $name);
            }
            $target[ $name ] = (string) $snapshot['data']['value'];
        }

        $stylesheet = wp_get_theme($target['stylesheet']);
        if ('' === $target['stylesheet'] || ! $stylesheet->exists()) {
            return sprintf('the theme "%s" is not installed any more, so it cannot be the active stylesheet.', $target['stylesheet']);
        }
        if (! wp_get_theme($target['template'])->exists()) {
            return sprintf('the theme "%s" is not installed any more, so it cannot be the active template.', $target['template']);
        }
        if ($stylesheet->get_template() !== $target['template']) {
            return sprintf(
                'it would leave the stylesheet "%s" (whose parent theme is "%s") with the template "%s", a pair that breaks the whole site. Use rollback-session for the switch\'s session to undo the whole switch.',
                $target['stylesheet'],
                $stylesheet->get_template(),
                $target['template']
            );
        }
        return null;
    }
}
