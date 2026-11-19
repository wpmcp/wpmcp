<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter, Groundhogg, MC4WP and Brevo mailing lists (issue #683) on the
 * plugin-data pair, paid-tier, so they add no top-level tools. Each op is
 * prefixed with the plugin and named the way the FluentCRM ops are
 * (newsletter-list-contacts, groundhogg-list-lists, ...):
 *  - Newsletter (Mailing_List_Newsletter): summary, lists, contacts (masked
 *    in lists), one contact, forms, and undoable contact writes (create,
 *    update or unsubscribe, add to and remove from lists), off until
 *    wpmcp_enable_newsletter_write;
 *  - Groundhogg (Mailing_List_Groundhogg): summary, tags (its lists),
 *    contacts (masked) and form steps, read only;
 *  - MC4WP and Brevo (Mailing_List_Remote): summary, the lists the plugin
 *    last cached from the remote account, and forms, read only. Their
 *    contacts live in the remote account, so they are not read or written
 *    here.
 *
 * Presence is filterable per plugin (wpmcp_newsletter_active,
 * wpmcp_groundhogg_active, wpmcp_mc4wp_active, wpmcp_brevo_active).
 */
final class Mailing_List_Pack
{
    /** Contacts or forms one page holds at most. */
    public const MAX_PER_PAGE = 100;

    /** Whether one of the plugins is loaded and this install may run the paid tier. */
    public static function active(): bool
    {
        if (! Registrar::tier_permitted('pro')) {
            return false;
        }
        return Mailing_List_Newsletter::active() || Mailing_List_Groundhogg::active() || Mailing_List_Remote::mc4wp_active() || Mailing_List_Remote::brevo_active();
    }

    /**
     * Plugin name => its ops, for the per-op plugin names of the pair.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function hosts(): array
    {
        return [
            'Newsletter' => Mailing_List_Newsletter::operations(),
            'Groundhogg' => Mailing_List_Groundhogg::operations(),
            'MC4WP'      => Mailing_List_Remote::mc4wp_operations(),
            'Brevo'      => Mailing_List_Remote::brevo_operations(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(...array_values(self::hosts()));
    }

    /**
     * true when the plugin is loaded, else plugin_missing or plugin_inactive
     * naming it, the plugins screen and its main file.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence(bool $active, string $plugin, string $file)
    {
        if ($active) {
            return true;
        }
        $refusal = Plugin_Refusal::make('plugin_missing', $plugin, $file);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** Clamp paging into a limit and an offset. @return array{0: int, 1: int, 2: int, 3: int} [limit, offset, page, per_page] */
    public static function paging(array $args): array
    {
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        return [ $per_page, $per_page * ($page - 1), $page, $per_page ];
    }

    /** The paging schema every list op takes. @return array<string, array<string, mixed>> */
    public static function paging_schema(): array
    {
        return [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
    }

    /** Whether a table exists (SHOW COLUMNS, which also sees temporary tables). */
    public static function table_exists(string $table): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }
}
