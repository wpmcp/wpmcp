<?php

namespace WPMCP\Admin;

use WPMCP\Memory\Memory_Entry;
use WPMCP\Memory\Memory_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The admin side of agent project memory (issue #131).
 *
 * There is deliberately no bespoke approval screen. The wpmcp_memory CPT is
 * registered with show_ui on, so approving a proposal is WordPress's own
 * pending -> publish flow with its own list table, nonces, capability checks
 * and revisions. This class supplies only the three things that flow does not
 * give for free:
 *
 *  - the submenu entry that hooks the CPT list table under the wpmcp menu,
 *  - a metabox for the fields that are not title/content (kind, severity,
 *    targets), saved through Memory_Store so admin edits are validated by
 *    exactly the same rules as agent proposals,
 *  - the pending-proposal count badge, rendered in the WordPress bubble style
 *    on both the wpmcp top-level menu and the memory submenu, so a waiting
 *    proposal is visible from any wpmcp screen.
 */
class Memory_Page
{
    public const NONCE = 'wpmcp_memory_meta';

    /** admin_menu slug for the CPT list table. */
    public static function submenu_slug(): string
    {
        return 'edit.php?post_type=' . Memory_Store::POST_TYPE;
    }

    /**
     * Appends the WordPress count bubble to a menu title when proposals are
     * waiting. Returns $label unchanged when the count is zero, so a quiet
     * site sees no decoration at all.
     */
    public static function badged(string $label, ?int $count = null): string
    {
        $count = null === $count ? self::pending_count() : $count;
        if ($count < 1) {
            return $label;
        }

        return sprintf(
            '%s <span class="awaiting-mod update-plugins count-%d"><span class="pending-count">%s</span></span>',
            $label,
            $count,
            number_format_i18n($count)
        );
    }

    /** Pending proposals awaiting a human decision; 0 if the CPT is absent. */
    public static function pending_count(): int
    {
        if (! post_type_exists(Memory_Store::POST_TYPE)) {
            return 0;
        }
        return Memory_Store::pending_count();
    }

    /** Hook wiring, called from Plugin::boot() when the memory group is on. */
    public function register_hooks(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post_' . Memory_Store::POST_TYPE, [$this, 'save_meta_box'], 10, 1);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'wpmcp-memory-fields',
            'Memory entry',
            [$this, 'render_meta_box'],
            Memory_Store::POST_TYPE,
            'side'
        );
    }

    /** @param \WP_Post $post */
    public function render_meta_box($post): void
    {
        $entry = Memory_Store::get((int) $post->ID) ?? ['kind' => 'fact', 'severity' => 'note', 'targets' => []];

        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<p><label for="wpmcp-memory-kind"><strong>' . esc_html__('Kind', 'wpmcp') . '</strong></label><br />';
        echo '<select id="wpmcp-memory-kind" name="wpmcp_memory_kind">';
        foreach (Memory_Entry::KINDS as $kind) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($kind),
                selected($entry['kind'], $kind, false),
                esc_html($kind)
            );
        }
        echo '</select></p>';

        echo '<p><label for="wpmcp-memory-severity"><strong>' . esc_html__('Severity', 'wpmcp') . '</strong></label><br />';
        echo '<select id="wpmcp-memory-severity" name="wpmcp_memory_severity">';
        foreach (Memory_Entry::SEVERITIES as $severity) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($severity),
                selected($entry['severity'], $severity, false),
                esc_html($severity)
            );
        }
        echo '</select></p>';

        echo '<p><label for="wpmcp-memory-targets"><strong>' . esc_html__('Targets (one per line)', 'wpmcp') . '</strong></label><br />';
        printf(
            '<textarea id="wpmcp-memory-targets" name="wpmcp_memory_targets" rows="4" class="widefat">%s</textarea>',
            esc_textarea(implode("\n", (array) $entry['targets']))
        );
        echo '</p>';

        echo '<p class="description">' . esc_html__(
            'Published severity=block entries are enforced by the server: every matching call is refused before it runs. tool: targets are exact; post_id:/post_type: targets match only calls whose arguments name that post or type.',
            'wpmcp'
        ) . '</p>';
    }

    /**
     * Persist the metabox fields. Refuses autosaves, bad/absent nonces, and
     * users without manage_options. Validation is Memory_Store::update_fields
     * (i.e. Memory_Entry), the same path agent proposals take, so an admin
     * cannot store a malformed target or an untargeted block rule either.
     */
    public function save_meta_box(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
        $posted = $_POST;
        if (! isset($posted[ self::NONCE ]) || ! wp_verify_nonce(sanitize_key($posted[ self::NONCE ]), self::NONCE)) {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }
        if (! Memory_Store::is_entry($post_id)) {
            return;
        }

        $post = get_post($post_id);

        Memory_Store::update_fields($post_id, [
            'title'    => null === $post ? '' : $post->post_title,
            'text'     => null === $post ? '' : $post->post_content,
            'kind'     => isset($posted['wpmcp_memory_kind']) ? sanitize_text_field($posted['wpmcp_memory_kind']) : 'fact',
            'severity' => isset($posted['wpmcp_memory_severity']) ? sanitize_text_field($posted['wpmcp_memory_severity']) : 'note',
            'targets'  => self::parse_targets(isset($posted['wpmcp_memory_targets']) ? (string) $posted['wpmcp_memory_targets'] : ''),
        ]);
    }

    /** @return string[] one target per non-empty line. */
    public static function parse_targets(string $raw): array
    {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $out   = [];
        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ('' !== $line) {
                $out[] = $line;
            }
        }
        return $out;
    }
}
