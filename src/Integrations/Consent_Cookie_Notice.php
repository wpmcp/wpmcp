<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cookie Notice (Cookie Compliance for WordPress, 3.x) for Consent_Pack
 * (issue #686). Its banner settings and texts are one option,
 * cookie_notice_options; cookie_notice_status says whether the Cookie
 * Compliance web app is connected, in which case the app's widget replaces
 * the plugin's own notice and these texts are not shown. The plugin keeps no
 * cookie list and runs no scan on the site.
 */
final class Consent_Cookie_Notice extends Consent_Adapter
{
    public const OPTION = 'cookie_notice_options';

    /** text key => [path in the option, kind]. */
    private const TEXTS = [
        'message_text'        => [ 'message_text', 'html' ],
        'accept_text'         => [ 'accept_text', 'text' ],
        'refuse_text'         => [ 'refuse_text', 'text' ],
        'revoke_message_text' => [ 'revoke_message_text', 'html' ],
        'revoke_text'         => [ 'revoke_text', 'text' ],
        'privacy_link_text'   => [ 'see_more_opt.text', 'text' ],
    ];

    /** Readable settings, as stored. app_id and app_key are reported only as set or not. */
    private const SETTINGS = [
        'position', 'refuse_opt', 'revoke_cookies', 'revoke_cookies_opt', 'see_more', 'link_target', 'link_position',
        'time', 'time_rejected', 'hide_effect', 'on_scroll', 'on_scroll_offset', 'on_click', 'colors',
        'script_placement', 'conditional_active', 'conditional_display', 'wp_consent_api', 'bot_detection',
        'caching_compatibility', 'app_blocking', 'app_blocking_engine', 'ui_mode', 'displayType',
    ];

    public function slug(): string
    {
        return 'cookie-notice';
    }

    public function label(): string
    {
        return 'Cookie Notice';
    }

    public function plugin_file(): string
    {
        return 'cookie-notice/cookie-notice.php';
    }

    protected function loaded(): bool
    {
        return class_exists('Cookie_Notice', false);
    }

    public function version(): string
    {
        return (string) get_option('cookie_notice_version', '');
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** 'active' or 'pending' once the Cookie Compliance web app is connected. */
    private function app_status(): string
    {
        $status = get_option('cookie_notice_status', []);
        return is_array($status) ? sanitize_key((string) ($status['status'] ?? '')) : '';
    }

    public function status(): array
    {
        $app = in_array($this->app_status(), [ 'active', 'pending' ], true);
        return [
            'banner_enabled' => true,
            'managed_by'     => $app ? 'cookie_compliance_app' : 'plugin',
            'app_status'     => $this->app_status(),
            'note'           => $app
                ? 'The Cookie Compliance web app is connected, so its widget is shown instead of the plugin notice and the texts here are not used.'
                : 'The plugin shows its own notice while it is active.',
        ];
    }

    public function settings(): array
    {
        $stored = $this->stored();
        $out    = [];
        foreach (self::SETTINGS as $key) {
            if (array_key_exists($key, $stored)) {
                $out[ $key ] = $stored[ $key ];
            }
        }
        $out['see_more_link'] = [
            'type'    => (string) self::path_get($stored, 'see_more_opt.link_type'),
            'page_id' => (int) self::path_get($stored, 'see_more_opt.id'),
            'url'     => (string) self::path_get($stored, 'see_more_opt.link'),
        ];
        $out['refuse_code_set'] = '' !== trim((string) ($stored['refuse_code'] ?? '')) || '' !== trim((string) ($stored['refuse_code_head'] ?? ''));
        $out['masked']          = [
            [ 'name' => 'app_id', 'set' => '' !== (string) ($stored['app_id'] ?? '') ],
            [ 'name' => 'app_key', 'set' => '' !== (string) ($stored['app_key'] ?? '') ],
        ];
        return $out;
    }

    public function text_keys(): array
    {
        return array_map(static fn (array $t): string => $t[1], self::TEXTS);
    }

    public function banner(array $args): array
    {
        return [ 'id' => self::OPTION ];
    }

    public function texts(array $banner): array
    {
        $stored = $this->stored();
        $out    = [];
        foreach (self::TEXTS as $key => $text) {
            $out[ $key ] = (string) self::path_get($stored, $text[0]);
        }
        return $out;
    }

    public function write_texts(array $banner, array $changes): void
    {
        $stored = $this->stored();
        foreach ($changes as $key => $value) {
            $stored = self::path_set($stored, self::TEXTS[ $key ][0], $value);
        }
        update_option('cookie_notice_options', $stored);
    }

    public function snapshot(array $banner, array $changes): ?array
    {
        return [ 'object_type' => 'option', 'object_id' => self::OPTION ];
    }
}
