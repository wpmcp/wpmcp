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
        'CartFlows'        => [ 'file' => 'cartflows/cartflows.php', 'slug' => 'cartflows' ],
        'FunnelKit Funnel Builder Pro' => [ 'file' => 'funnel-builder-pro/funnel-builder-pro.php', 'url' => 'https://funnelkit.com/' ],
        'Duplicator'       => [ 'file' => 'duplicator/duplicator.php', 'slug' => 'duplicator' ],
        'TablePress'       => [ 'file' => 'tablepress/tablepress.php', 'slug' => 'tablepress' ],
        'MemberPress'      => [ 'file' => 'memberpress/memberpress.php', 'url' => 'https://memberpress.com/' ],
        'Paid Memberships Pro' => [ 'file' => 'paid-memberships-pro/paid-memberships-pro.php', 'slug' => 'paid-memberships-pro' ],
        'LearnDash'        => [ 'file' => 'sfwd-lms/sfwd_lms.php', 'url' => 'https://www.learndash.com/' ],
        'LifterLMS'        => [ 'file' => 'lifterlms/lifterlms.php', 'slug' => 'lifterlms' ],
        'Tutor LMS'        => [ 'file' => 'tutor/tutor.php', 'slug' => 'tutor' ],
        'FluentCRM'        => [ 'file' => 'fluent-crm/fluent-crm.php', 'slug' => 'fluent-crm' ],
        'MailPoet'         => [ 'file' => 'mailpoet/mailpoet.php', 'slug' => 'mailpoet' ],
        'Fluent Support'   => [ 'file' => 'fluent-support/fluent-support.php', 'slug' => 'fluent-support' ],
        'Awesome Support'  => [ 'file' => 'awesome-support/awesome-support.php', 'slug' => 'awesome-support' ],
        'BetterDocs'       => [ 'file' => 'betterdocs/betterdocs.php', 'slug' => 'betterdocs' ],
        'Restrict Content' => [ 'file' => 'restrict-content/restrictcontent.php', 'slug' => 'restrict-content' ],
        'Ultimate Member'  => [ 'file' => 'ultimate-member/ultimate-member.php', 'slug' => 'ultimate-member' ],
        'Wordfence'        => [ 'file' => 'wordfence/wordfence.php', 'slug' => 'wordfence' ],
        'MonsterInsights'  => [ 'file' => 'google-analytics-for-wordpress/googleanalytics.php', 'slug' => 'google-analytics-for-wordpress' ],
        'Independent Analytics' => [ 'file' => 'independent-analytics/iawp.php', 'slug' => 'independent-analytics' ],
        'Burst Statistics' => [ 'file' => 'burst-statistics/burst.php', 'slug' => 'burst-statistics' ],
        'WP Statistics'    => [ 'file' => 'wp-statistics/wp-statistics.php', 'slug' => 'wp-statistics' ],
        'Jetpack'          => [ 'file' => 'jetpack/jetpack.php', 'slug' => 'jetpack' ],
        'W3 Total Cache'   => [ 'file' => 'w3-total-cache/w3-total-cache.php', 'slug' => 'w3-total-cache' ],
        'Query Monitor'    => [ 'file' => 'query-monitor/query-monitor.php', 'slug' => 'query-monitor' ],
        'Relevanssi'       => [ 'file' => 'relevanssi/relevanssi.php', 'slug' => 'relevanssi' ],
        'FiboSearch'       => [ 'file' => 'ajax-search-for-woocommerce/ajax-search-for-woocommerce.php', 'slug' => 'ajax-search-for-woocommerce' ],
        'Solid Security'   => [ 'file' => 'better-wp-security/better-wp-security.php', 'slug' => 'better-wp-security' ],
        'Sucuri Security'  => [ 'file' => 'sucuri-scanner/sucuri.php', 'slug' => 'sucuri-scanner' ],
        'All In One WP Security' => [ 'file' => 'all-in-one-wp-security-and-firewall/wp-security.php', 'slug' => 'all-in-one-wp-security-and-firewall' ],
        'Limit Login Attempts Reloaded' => [ 'file' => 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'slug' => 'limit-login-attempts-reloaded' ],
        'UpdraftPlus'      => [ 'file' => 'updraftplus/updraftplus.php', 'slug' => 'updraftplus' ],
        'BackWPup'         => [ 'file' => 'backwpup/backwpup.php', 'slug' => 'backwpup' ],
        'Code Snippets'    => [ 'file' => 'code-snippets/code-snippets.php', 'slug' => 'code-snippets' ],
        'WPCode'           => [ 'file' => 'insert-headers-and-footers/ihaf.php', 'slug' => 'insert-headers-and-footers' ],
        'bbPress'          => [ 'file' => 'bbpress/bbpress.php', 'slug' => 'bbpress' ],
        'BuddyPress'       => [ 'file' => 'buddypress/bp-loader.php', 'slug' => 'buddypress' ],
        'BuddyBoss Platform' => [ 'file' => 'buddyboss-platform/bp-loader.php', 'url' => 'https://www.buddyboss.com/platform/' ],
        'JetEngine'        => [ 'file' => 'jet-engine/jet-engine.php', 'url' => 'https://crocoblock.com/plugins/jetengine/' ],
        'Pods'             => [ 'file' => 'pods/init.php', 'slug' => 'pods' ],
        'TranslatePress'   => [ 'file' => 'translatepress-multilingual/index.php', 'slug' => 'translatepress-multilingual' ],
        'Rank Math SEO'    => [ 'file' => 'seo-by-rank-math/rank-math.php', 'slug' => 'seo-by-rank-math' ],
        'Redirection'      => [ 'file' => 'redirection/redirection.php', 'slug' => 'redirection' ],
        'Flamingo'         => [ 'file' => 'flamingo/flamingo.php', 'slug' => 'flamingo' ],
        'ACF Pro'          => [ 'file' => 'advanced-custom-fields-pro/acf.php', 'url' => 'https://www.advancedcustomfields.com/pro/' ],
        'SeedProd'         => [ 'file' => 'coming-soon/coming-soon.php', 'slug' => 'coming-soon' ],
        'WPGraphQL'        => [ 'file' => 'wp-graphql/wp-graphql.php', 'slug' => 'wp-graphql' ],
        'Under Construction' => [ 'file' => 'under-construction-page/under-construction.php', 'slug' => 'under-construction-page' ],
        'Weglot'           => [ 'file' => 'weglot/weglot.php', 'slug' => 'weglot' ],
        'GTranslate'       => [ 'file' => 'gtranslate/gtranslate.php', 'slug' => 'gtranslate' ],
        'SureCart'         => [ 'file' => 'surecart/surecart.php', 'slug' => 'surecart' ],
        'Smush'            => [ 'file' => 'wp-smushit/wp-smush.php', 'slug' => 'wp-smushit' ],
        'ShortPixel'       => [ 'file' => 'shortpixel-image-optimiser/wp-shortpixel.php', 'slug' => 'shortpixel-image-optimiser' ],
        'EWWW Image Optimizer' => [ 'file' => 'ewww-image-optimizer/ewww-image-optimizer.php', 'slug' => 'ewww-image-optimizer' ],
        'Imagify'          => [ 'file' => 'imagify/imagify.php', 'slug' => 'imagify' ],
        'Checkout Field Editor for WooCommerce' => [ 'file' => 'woo-checkout-field-editor-pro/checkout-form-designer.php', 'slug' => 'woo-checkout-field-editor-pro' ],
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
     * The refusal payload when any one of several plugins would do: the
     * message gains the next step and the plugins screen, the data the
     * plugins_url.
     *
     * @param array<string, mixed> $data
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    public static function any_of(string $code, string $message, array $data = []): array
    {
        $plugins_url = admin_url('plugins.php');

        return [
            'code'    => $code,
            'message' => sprintf('%s Install and activate one of them (%s), then try again.', $message, $plugins_url),
            'data'    => $data + [ 'plugins_url' => $plugins_url ],
        ];
    }

    /**
     * Other directories the same plugin can live in (a premium build), so
     * one installed there is not reported as missing.
     *
     * @var array<string, string[]>
     */
    private const ALT_FILES = [
        'WP Mail SMTP'    => [ 'wp-mail-smtp-pro/wp_mail_smtp.php' ],
        'Code Snippets'   => [ 'code-snippets-pro/code-snippets.php' ],
        'Solid Security'  => [ 'ithemes-security-pro/ithemes-security-pro.php' ],
        'Tutor LMS'       => [ 'tutor-pro/tutor-pro.php' ],
    ];

    /**
     * The refusal payload for an op whose plugin is not loaded: the next
     * step ("Install and activate X (plugins screen), then try again.") in
     * the message, the same shape WS Form and the activity log ops use, and
     * in 'data' the plugin, whether it is installed, the plugins screen,
     * the main plugin file when known (required_plugin, what activate-plugin
     * takes, issue #823) and where to get it.
     *
     * @param string      $code   The pack's own error code.
     * @param string      $plugin The plugin's display name.
     * @param string|null $file   Main plugin file, when the caller knows it (overrides the table).
     * @return array{code: string, message: string, data: array<string, string>}
     */
    public static function make(string $code, string $plugin, ?string $file = null): array
    {
        $known     = self::PLUGINS[ $plugin ] ?? null;
        $file      = $file ?? ($known['file'] ?? '');
        $installed = '';
        foreach (array_merge([ $file ], self::ALT_FILES[ $plugin ] ?? []) as $candidate) {
            if ('' === $installed && '' !== $candidate && defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $candidate)) {
                $installed = $candidate;
            }
        }
        $plugins_url = admin_url('plugins.php');
        $install_url = self::install_url($plugin) ?? '';

        $data = [
            'plugin'      => $known['slug'] ?? sanitize_title($plugin),
            'plugin_name' => $plugin,
            'state'       => '' !== $installed ? 'inactive' : 'missing',
            'plugins_url' => $plugins_url,
        ];
        if ('' !== $installed || '' !== $file) {
            $data['required_plugin'] = '' !== $installed ? $installed : $file;
        }
        if ('' !== $install_url) {
            $data['install_url'] = $install_url;
        }

        if ('' !== $installed) {
            $message = sprintf('%1$s is installed but not active on this site. Install and activate %1$s (%2$s), then try again.', $plugin, $plugins_url);
        } elseif ('' !== $install_url) {
            $message = sprintf('%1$s is not installed on this site. Install and activate %1$s (%2$s), then try again. It is available from %3$s.', $plugin, $plugins_url, $install_url);
        } else {
            $message = sprintf('%1$s is not installed on this site. Install and activate %1$s (%2$s), then try again.', $plugin, $plugins_url);
        }

        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
