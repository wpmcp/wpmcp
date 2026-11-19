<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hello Elementor settings pack (issue #655), built by Theme_Settings_Pack.
 *
 * Hello has no design settings of its own (its look is Elementor's site
 * kit); what it stores are the six switches on its Settings page, each a
 * separate option (Settings_Controller::SETTINGS) that turns a theme
 * feature OFF when it holds the string 'true'. They are exposed as
 * disable_* booleans, written as 'true' or '' exactly as that page does.
 * The page needs manage_options, so the pack does too.
 */
final class Theme_Pack_Hello
{
    /** Pack key => the option suffix after hello_elementor_settings. */
    private const TOGGLES = [
        'disable_description_meta_tag' => '_description_meta_tag',
        'disable_skip_link'            => '_skip_link',
        'disable_header_footer'        => '_header_footer',
        'disable_page_title'           => '_page_title',
        'disable_reset_style'          => '_hello_style',
        'disable_theme_style'          => '_hello_theme',
    ];

    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $stores   = [];
        $settings = [];
        foreach (self::TOGGLES as $key => $suffix) {
            $stores[ $key ]   = [ 'type' => 'scalar_option', 'option' => 'hello_elementor_settings' . $suffix ];
            $settings[ $key ] = [ 'store' => $key, 'path' => [ Theme_Settings_Pack::SCALAR ], 'rule' => [ 'rule' => 'flag' ] ];
        }

        return [
            'family'           => 'hello-elementor',
            'label'            => 'Hello Elementor',
            'tier'             => 'free',
            'capability'       => 'manage_options',
            'read_description' => 'Read the Hello Elementor theme switches (disable meta description, skip link, header/footer, page title, reset and theme styles) as booleans, with the allowlist',
            'stores'           => $stores,
            'settings'         => $settings,
        ];
    }
}
