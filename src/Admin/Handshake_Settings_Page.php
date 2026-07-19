<?php

namespace WPMCP\Admin;

use WPMCP\MCP\Handshake_Instructions;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin screen for the handshake instructions option (issue #80): a single
 * textarea whose contents are served, merged with the auto-generated site
 * summary, in the MCP initialize response's `instructions` field — i.e. to
 * EVERY agent that connects, before authorization-gated context is added.
 * Gated at manage_options like the other wpmcp admin screens.
 *
 * Saved through the Settings API (options.php) so the sanitize callback
 * below is the single write path from this UI: markup is stripped (with
 * script/style contents removed, newlines preserved) and the text is
 * clamped to Handshake_Instructions::MAX_ADMIN_LENGTH so the handshake
 * payload stays bounded.
 */
class Handshake_Settings_Page
{
    public const GROUP = 'wpmcp_handshake';

    /** Hooked on admin_init: register the option with its sanitizer. */
    public static function register_setting(): void
    {
        register_setting(self::GROUP, Handshake_Instructions::OPTION, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);
    }

    /**
     * Strip markup (sanitize_textarea_field removes tags including
     * script/style bodies while preserving newlines) and clamp to the
     * documented bound. Non-strings collapse to ''.
     *
     * @param mixed $value Raw submitted option value.
     */
    public static function sanitize($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_substr(sanitize_textarea_field($value), 0, Handshake_Instructions::MAX_ADMIN_LENGTH);
    }

    public function render(): void
    {
        $option  = Handshake_Instructions::OPTION;
        $value   = get_option($option, '');
        $preview = (new Handshake_Instructions())->auto_summary();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('wpmcp: Handshake Instructions', 'wpmcp'); ?></h1>
            <p>
                <?php
                echo esc_html__(
                    'This text is served to every AI agent that connects, inside the MCP initialize response, ahead of an auto-generated site summary. Plain text only; markup is stripped.',
                    'wpmcp'
                );
                ?>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <textarea
                    name="<?php echo esc_attr($option); ?>"
                    id="<?php echo esc_attr($option); ?>"
                    class="large-text"
                    rows="10"
                    maxlength="<?php echo (int) Handshake_Instructions::MAX_ADMIN_LENGTH; ?>"
                ><?php echo esc_textarea(is_string($value) ? $value : ''); ?></textarea>
                <?php submit_button(); ?>
            </form>
            <h2><?php echo esc_html__('Auto-generated summary (appended for authorized connections)', 'wpmcp'); ?></h2>
            <p><code><?php echo esc_html($preview); ?></code></p>
        </div>
        <?php
    }
}
