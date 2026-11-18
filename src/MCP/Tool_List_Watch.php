<?php

namespace WPMCP\MCP;

use WPMCP\Governance\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Notices, between messages of a long-lived stdio session, that the set of
 * tools this site can offer has changed (issue #632): a plugin activated or
 * deactivated, a license added or lapsed, a governance toggle, the tool
 * exposure mode. The stdio transport then sends one
 * notifications/tools/list_changed.
 *
 * What it compares is a fingerprint of the inputs that decide availability,
 * not the advertised list itself: abilities are registered once, at process
 * start, so a plugin activated in another process (wp-admin) changes what a
 * fresh session would see without changing this process's registry. A
 * client that honours the notification re-lists; one that ignores it, or a
 * change this process cannot load, needs a reconnect, which is what the
 * refusal for a held tool tells the person (Registrar::plugin_inactive).
 *
 * Read-only: it reads options and the tier rule and writes nothing. An
 * integration or the paid tier can add its own input through the
 * wpmcp_tool_list_fingerprint filter, so this shared class names none.
 */
class Tool_List_Watch
{
    private ?string $baseline = null;

    /** Starts watching from the current state (the session's initialize). */
    public function start(): void
    {
        $this->baseline = self::fingerprint();
    }

    /**
     * True once per change since start() or the last true, false before
     * start() and while nothing changed.
     */
    public function changed(): bool
    {
        if (null === $this->baseline) {
            return false;
        }
        $now = self::fingerprint();
        if ($now === $this->baseline) {
            return false;
        }
        $this->baseline = $now;
        return true;
    }

    /** A hash of every input that decides which tools this site offers. */
    public static function fingerprint(): string
    {
        $plugins = array_map('strval', (array) get_option('active_plugins', []));
        sort($plugins);
        $network = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [];
        sort($network);

        $parts = [
            'plugins'    => $plugins,
            'network'    => $network,
            'paid_tier'  => Registrar::tier_permitted('pro'),
            'governance' => get_option(Governance::OPTION, []),
            'exposure'   => (string) get_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_FULL),
        ];

        /**
         * Extra inputs that decide tool availability, keyed by a name.
         *
         * @param array<string, mixed> $parts
         */
        $parts = apply_filters('wpmcp_tool_list_fingerprint', $parts);

        return md5((string) wp_json_encode($parts));
    }
}
