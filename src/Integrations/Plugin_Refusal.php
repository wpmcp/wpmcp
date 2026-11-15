<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one shape every integration pack uses to say its plugin is not
 * available (issue #877): the plugin named, whether it is installed but off
 * or not installed at all, the wp-admin plugins link to activate it, and,
 * for a plugin the site does not have, where to get it (the wordpress.org
 * page for a free plugin, the vendor page for a premium one).
 *
 * The error code is the caller's own (betterdocs_inactive and so on), so a
 * client that matches on it keeps working; the new facts are in 'data'
 * (plugin, plugin_name, state, plugins_url, install_url).
 */
final class Plugin_Refusal
{
    /**
     * Known plugins by display name: main plugin file (for the installed
     * check), wordpress.org slug for a free plugin, or a vendor page.
     *
     * @var array<string, array{file: string, slug?: string, url?: string}>
     */
    private const PLUGINS = [
        'WP Mail SMTP'     => [ 'file' => 'wp-mail-smtp/wp_mail_smtp.php', 'slug' => 'wp-mail-smtp' ],
        'Amelia'           => [ 'file' => 'ameliabooking/ameliabooking.php', 'slug' => 'ameliabooking' ],
        'FunnelKit'        => [ 'file' => 'funnel-builder/funnel-builder.php', 'slug' => 'funnel-builder' ],
        'Duplicator'       => [ 'file' => 'duplicator/duplicator.php', 'slug' => 'duplicator' ],
        'TablePress'       => [ 'file' => 'tablepress/tablepress.php', 'slug' => 'tablepress' ],
        'MemberPress'      => [ 'file' => 'memberpress/memberpress.php', 'url' => 'https://memberpress.com/' ],
        'LearnDash'        => [ 'file' => 'sfwd-lms/sfwd_lms.php', 'url' => 'https://www.learndash.com/' ],
        'LifterLMS'        => [ 'file' => 'lifterlms/lifterlms.php', 'slug' => 'lifterlms' ],
        'Tutor LMS'        => [ 'file' => 'tutor/tutor.php', 'slug' => 'tutor' ],
        'FluentCRM'        => [ 'file' => 'fluent-crm/fluent-crm.php', 'slug' => 'fluent-crm' ],
        'MailPoet'         => [ 'file' => 'mailpoet/mailpoet.php', 'slug' => 'mailpoet' ],
        'Fluent Support'   => [ 'file' => 'fluent-support/fluent-support.php', 'slug' => 'fluent-support' ],
        'Awesome Support'  => [ 'file' => 'awesome-support/awesome-support.php', 'slug' => 'awesome-support' ],
        'BetterDocs'       => [ 'file' => 'betterdocs/betterdocs.php', 'slug' => 'betterdocs' ],
        'Wordfence'        => [ 'file' => 'wordfence/wordfence.php', 'slug' => 'wordfence' ],
        'MonsterInsights'  => [ 'file' => 'google-analytics-for-wordpress/googleanalytics.php', 'slug' => 'google-analytics-for-wordpress' ],
        'W3 Total Cache'   => [ 'file' => 'w3-total-cache/w3-total-cache.php', 'slug' => 'w3-total-cache' ],
        'Solid Security'   => [ 'file' => 'better-wp-security/better-wp-security.php', 'slug' => 'better-wp-security' ],
        'UpdraftPlus'      => [ 'file' => 'updraftplus/updraftplus.php', 'slug' => 'updraftplus' ],
        'Code Snippets'    => [ 'file' => 'code-snippets/code-snippets.php', 'slug' => 'code-snippets' ],
        'bbPress'          => [ 'file' => 'bbpress/bbpress.php', 'slug' => 'bbpress' ],
        'BuddyPress'       => [ 'file' => 'buddypress/bp-loader.php', 'slug' => 'buddypress' ],
        'JetEngine'        => [ 'file' => 'jet-engine/jet-engine.php', 'url' => 'https://crocoblock.com/plugins/jetengine/' ],
        'Pods'             => [ 'file' => 'pods/init.php', 'slug' => 'pods' ],
        'TranslatePress'   => [ 'file' => 'translatepress-multilingual/index.php', 'slug' => 'translatepress-multilingual' ],
        'Rank Math SEO'    => [ 'file' => 'seo-by-rank-math/rank-math.php', 'slug' => 'seo-by-rank-math' ],
        'Redirection'      => [ 'file' => 'redirection/redirection.php', 'slug' => 'redirection' ],
    ];

    /** Where to get a known plugin: its wordpress.org page or vendor page, else null. */
    public static function install_url(string $plugin): ?string
    {
        $known = self::PLUGINS[ $plugin ] ?? null;
        if (isset($known['slug'])) {
            return 'https://wordpress.org/plugins/' . $known['slug'] . '/';
        }
        return $known['url'] ?? null;
    }

    /**
     * The refusal payload for an op whose plugin is not loaded.
     *
     * @param string      $code   The pack's own error code.
     * @param string      $plugin The plugin's display name.
     * @param string|null $file   Main plugin file, when the caller knows it (overrides the table).
     * @return array{code: string, message: string, data: array<string, string>}
     */
    public static function make(string $code, string $plugin, ?string $file = null): array
    {
        $known       = self::PLUGINS[ $plugin ] ?? null;
        $file        = $file ?? ($known['file'] ?? '');
        $installed   = '' !== $file && defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file);
        $plugins_url = admin_url('plugins.php');
        $install_url = '';
        if (isset($known['slug'])) {
            $install_url = 'https://wordpress.org/plugins/' . $known['slug'] . '/';
        } elseif (isset($known['url'])) {
            $install_url = $known['url'];
        }

        $data = [
            'plugin'      => $known['slug'] ?? sanitize_title($plugin),
            'plugin_name' => $plugin,
            'state'       => $installed ? 'inactive' : 'missing',
            'plugins_url' => $plugins_url,
        ];
        if ('' !== $install_url) {
            $data['install_url'] = $install_url;
        }

        if ($installed) {
            $message = sprintf('%1$s is installed but not active on this site. Activate %1$s (%2$s), then try again.', $plugin, $plugins_url);
        } elseif ('' !== $install_url) {
            $message = sprintf('%1$s is not installed on this site. Install it from %2$s and activate it (%3$s), then try again.', $plugin, $install_url, $plugins_url);
        } else {
            $message = sprintf('%1$s is not installed on this site. Install and activate %1$s (%2$s), then try again.', $plugin, $plugins_url);
        }

        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
