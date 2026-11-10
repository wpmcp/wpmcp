<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\CustomCode\Custom_Js_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor Custom Code snippet (an `elementor_snippet` post with the
 * meta Elementor Pro reads: _elementor_code, _elementor_location,
 * _elementor_priority). The snippet is stored on any site; it renders where
 * Elementor Pro's Custom Code module is active. Creating destroys nothing, so
 * it is not snapshot-wrapped; remove it with delete-code-snippet.
 *
 * The code is raw markup printed on every page, the same XSS-class surface
 * as add-custom-js (issue #558), so it clears the same two checks before
 * anything else, in the same order: the default-off JS injection gate
 * (Custom_Js_Guard::is_enabled(), listed in Opt_In_Gates), then
 * unfiltered_html on top of the ability's manage_options. Multisite strips
 * unfiltered_html from site admins, and DISALLOW_UNFILTERED_HTML from
 * everyone, so neither can plant script that runs for a super admin. Drafts
 * are refused too: a draft snippet is one status change from rendering.
 * Every attempt is audited without its payload.
 */
class Create_Code_Snippet
{
    public const ABILITY = 'wpmcp/create-code-snippet';

    /** Audit reasons, spelled as add-custom-js spells them. */
    public const REASON_GATE_CLOSED        = 'js-gate-closed';
    public const REASON_NO_UNFILTERED_HTML = 'no-unfiltered-html';
    public const REASON_STORED             = 'snippet-stored';

    private const VALID_LOCATIONS = ['wp_head', 'wp_body_open', 'wp_footer'];

    public function handle(array $args)
    {
        // The gate answers before the payload is validated, so an install
        // that never opened it does not reveal that the tool is live.
        if (! Custom_Js_Guard::is_enabled()) {
            return $this->refuse(
                'js_gate_closed',
                'Elementor custom code snippets are disabled. Enable them with the WPMCP_ALLOW_JS_INJECTION constant or the wpmcp_allow_js_injection filter. A snippet runs in every visitor\'s browser; only enable this deliberately.',
                self::REASON_GATE_CLOSED
            );
        }

        if (! Custom_Js_Guard::current_user_can_inject()) {
            return $this->refuse(
                'no_unfiltered_html',
                'Creating a code snippet requires the unfiltered_html capability in addition to manage_options.',
                self::REASON_NO_UNFILTERED_HTML
            );
        }

        $code = (string) ($args['code'] ?? '');
        if ('' === trim($code)) {
            return new \WP_Error('missing_code', 'A non-empty code string is required.');
        }

        $title    = sanitize_text_field((string) ($args['title'] ?? 'Code snippet'));
        $location = sanitize_key((string) ($args['location'] ?? 'wp_head'));
        if (! in_array($location, self::VALID_LOCATIONS, true)) {
            $location = 'wp_head';
        }
        $priority = max(1, min(10, (int) ($args['priority'] ?? 10)));
        $status   = 'draft' === ($args['status'] ?? 'publish') ? 'draft' : 'publish';

        $snippet_id = wp_insert_post(wp_slash([
            'post_title'  => '' !== $title ? $title : 'Code snippet',
            'post_type'   => 'elementor_snippet',
            'post_status' => $status,
        ]), true);

        if (is_wp_error($snippet_id)) {
            return $snippet_id;
        }
        $snippet_id = (int) $snippet_id;

        // update_post_meta() unslashes; a regex or string escape in the code must survive.
        update_post_meta($snippet_id, '_elementor_code', wp_slash($code));
        update_post_meta($snippet_id, '_elementor_location', $location);
        update_post_meta($snippet_id, '_elementor_priority', $priority);
        update_post_meta($snippet_id, '_elementor_template_type', 'code_snippet');
        update_post_meta($snippet_id, '_elementor_edit_mode', 'builder');

        Governance_Audit_Log::record_quietly(self::ABILITY, true, self::REASON_STORED);

        return [
            'snippet_id' => $snippet_id,
            'title'      => $title,
            'location'   => $location,
            'priority'   => $priority,
            'status'     => $status,
        ];
    }

    /** Record the denial (never the payload), then return it. */
    private function refuse(string $code, string $message, string $reason): \WP_Error
    {
        Governance_Audit_Log::record_quietly(self::ABILITY, false, $reason);

        return new \WP_Error($code, $message);
    }
}
